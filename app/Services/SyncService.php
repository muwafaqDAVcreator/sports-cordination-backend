<?php
namespace App\Services;

use App\Models\Athlete;
use App\Models\Event;
use App\Models\Result;
use App\Models\ScheduleDelay;
use App\Models\SyncConflict;
use App\Models\User;
use App\Support\EntityRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Synchronization engine (FR5, FR6).
 *
 * Processes an ordered batch of offline mutations uploaded by a client. Each
 * mutation is handled independently inside its own transaction and answered
 * with one of:
 *
 *   applied   - written to the server (possibly after winning a conflict)
 *   conflict  - the server holds a newer write; the client must adopt the
 *               returned server record (last-write-wins, server won)
 *   rejected  - failed validation/authorisation; the client keeps it marked
 *               failed with the reasons so the coordinator can correct it
 *
 * Guarantees:
 *   - Order:        mutations are applied in the order uploaded, so a parent
 *                   created offline exists before its children reference it.
 *   - Idempotency:  every mutation id is recorded with its response; a retry
 *                   after a dropped connection replays the stored response.
 *   - Same rules:   validation reuses EntityRules, exactly as online writes.
 *   - Auditability: every conflict is written to sync_conflicts with both
 *                   versions and the rule applied.
 *
 * Conflict rule (record-level LWW): a conflict exists when the server copy
 * was modified after the version the client last saw (`base_updated_at`).
 * The write with the later timestamp wins; ties go to the client.
 */
class SyncService {
    public const ENTITIES = ['event', 'athlete', 'result', 'delay'];
    public const OPERATIONS = ['create', 'update', 'delete'];

    public function process(User $user, array $mutations): array {
        $responses = [];

        foreach ($mutations as $mutation) {
            $responses[] = $this->processOne($user, $mutation);
        }

        $user->forceFill(['last_sync_at' => now()])->save();

        return $responses;
    }

    private function processOne(User $user, array $m): array {
        $mutationId = $m['id'] ?? null;

        $structural = Validator::make($m, [
            'id' => 'required|uuid',
            'entity' => 'required|in:' . implode(',', self::ENTITIES),
            'op' => 'required|in:' . implode(',', self::OPERATIONS),
            'uuid' => 'required|uuid',
            'data' => 'nullable|array',
            'client_updated_at' => 'nullable|date',
            'base_updated_at' => 'nullable|date',
        ]);
        if ($structural->fails()) {
            return ['id' => $mutationId, 'status' => 'rejected', 'errors' => $structural->errors()->toArray()];
        }

        $previous = DB::table('sync_mutations')->where('mutation_id', $mutationId)->first();
        if ($previous) {
            return array_merge(json_decode($previous->response, true), ['replayed' => true]);
        }

        try {
            $response = DB::transaction(fn () => $this->apply($user, $m));
        } catch (ValidationException $e) {
            $response = ['id' => $mutationId, 'status' => 'rejected', 'errors' => $e->errors()];
        } catch (AuthorizationException $e) {
            $response = ['id' => $mutationId, 'status' => 'rejected', 'errors' => ['auth' => ['Not permitted to modify this record']]];
        }

        DB::table('sync_mutations')->insert([
            'mutation_id' => $mutationId,
            'user_id' => $user->id,
            'entity' => $m['entity'],
            'operation' => $m['op'],
            'status' => $response['status'],
            'response' => json_encode($response),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $response;
    }

    private function apply(User $user, array $m): array {
        $entity = $m['entity'];
        $data = $m['data'] ?? [];
        $clientAt = $this->clientTime($m['client_updated_at'] ?? null);
        $baseAt = isset($m['base_updated_at']) ? Carbon::parse($m['base_updated_at']) : null;

        $model = $this->find($entity, $m['uuid']);

        // A result "create" for an athlete who already has a result recorded
        // by another device is the same logical record: merge into it.
        if (!$model && $entity === 'result' && $m['op'] !== 'delete') {
            $model = $this->existingResultFor($data);
        }

        if ($m['op'] === 'delete') {
            return $this->delete($user, $m, $model, $clientAt, $baseAt);
        }

        if (!$model) {
            if ($m['op'] === 'update') {
                return ['id' => $m['id'], 'status' => 'rejected', 'errors' => ['uuid' => ['Record no longer exists on the server']]];
            }
            $model = $this->create($user, $entity, $m['uuid'], $data, $clientAt);
            return $this->ok($m, $entity, $model);
        }

        $this->authorizeFor($user, $entity, $model);

        $isConflict = $this->isConflict($model, $baseAt, $m['op'], $m['uuid']);
        if ($isConflict && !$this->clientWins($model, $clientAt)) {
            $this->logConflict($user, $m, $entity, $model, 'server_wins', $clientAt);
            return [
                'id' => $m['id'],
                'status' => 'conflict',
                'resolution' => 'server_wins',
                'record' => SyncSerializer::any($entity, $model->fresh()),
            ];
        }

        if ($isConflict) {
            $this->logConflict($user, $m, $entity, $model, 'client_wins', $clientAt);
        }

        $this->update($entity, $model, $data, $clientAt);

        return $this->ok($m, $entity, $model->fresh(), $isConflict ? 'client_wins' : null);
    }

    private function create(User $user, string $entity, string $uuid, array $data, Carbon $clientAt): Model {
        switch ($entity) {
            case 'event':
                $valid = Validator::make($data, EntityRules::event())->validate();
                $model = new Event([...$valid, 'uuid' => $uuid, 'created_by' => $user->id]);
                break;

            case 'athlete':
                $event = $this->parentEvent($user, $data);
                $valid = Validator::make($data, EntityRules::athlete($event->id))->validate();
                $valid['bib_number'] ??= Athlete::nextBibNumber($event->id);
                $model = new Athlete([...$valid, 'uuid' => $uuid, 'event_id' => $event->id]);
                break;

            case 'result':
                $event = $this->parentEvent($user, $data);
                $athlete = $this->parentAthlete($event, $data);
                $valid = Validator::make($data, EntityRules::result($event->id))->validate();
                $model = new Result([...$valid, 'uuid' => $uuid, 'event_id' => $event->id, 'athlete_id' => $athlete->id]);
                break;

            case 'delay':
                $event = $this->parentEvent($user, $data);
                $valid = Validator::make($data, EntityRules::delay())->validate();
                $model = new ScheduleDelay([
                    ...$valid,
                    'uuid' => $uuid,
                    'event_id' => $event->id,
                    'severity' => ScheduleDelay::severityFor($valid['delay_minutes']),
                    'reported_by' => $user->id,
                ]);
                break;
        }

        $model->created_at = $clientAt;
        $model->updated_at = $clientAt;
        $model->save();

        if ($model instanceof ScheduleDelay) {
            $this->applyRevisedStart($model);
        }

        return $model;
    }

    private function update(string $entity, Model $model, array $data, Carbon $clientAt): void {
        $rules = match ($entity) {
            'event' => EntityRules::event(partial: true),
            'athlete' => EntityRules::athlete($model->event_id, $model->id, partial: true),
            'result' => EntityRules::result($model->event_id, $model->id),
            'delay' => EntityRules::delay(),
        };

        // Validate only the fields actually sent, but with full context for
        // cross-field rules (e.g. end_time after start_time).
        $context = $entity === 'event'
            ? array_merge(['start_time' => substr((string) $model->start_time, 0, 5)], $data)
            : $data;
        $valid = Validator::make($context, array_intersect_key($rules, $data))->validate();

        $model->fill($valid);
        if ($entity === 'delay' && isset($valid['delay_minutes'])) {
            $model->severity = ScheduleDelay::severityFor($valid['delay_minutes']);
        }
        $model->updated_at = $clientAt;
        $model->save();
    }

    private function delete(User $user, array $m, ?Model $model, Carbon $clientAt, ?Carbon $baseAt): array {
        if (!$model) {
            // Already gone: deleting is idempotent.
            return ['id' => $m['id'], 'status' => 'applied', 'record' => null, 'deleted' => true];
        }

        $this->authorizeFor($user, $m['entity'], $model);

        if ($m['entity'] === 'athlete' && $model->result()->exists()) {
            return ['id' => $m['id'], 'status' => 'rejected', 'errors' => ['athlete' => ['Athlete has recorded results and cannot be deleted']]];
        }

        if ($this->isConflict($model, $baseAt, 'delete', $m['uuid']) && !$this->clientWins($model, $clientAt)) {
            $this->logConflict($user, $m, $m['entity'], $model, 'server_wins', $clientAt);
            return ['id' => $m['id'], 'status' => 'conflict', 'resolution' => 'server_wins', 'record' => SyncSerializer::any($m['entity'], $model)];
        }

        $model->delete();

        return ['id' => $m['id'], 'status' => 'applied', 'record' => null, 'deleted' => true];
    }

    private function isConflict(Model $model, ?Carbon $baseAt, string $op, string $mutationUuid): bool {
        // Two devices created the same logical result independently.
        if ($op === 'create' && $model->uuid !== $mutationUuid) {
            return true;
        }
        if (!$baseAt || !$model->updated_at) {
            return false;
        }
        return $model->updated_at->greaterThan($baseAt->copy()->addSecond()); // 1 s tolerance for DB precision
    }

    private function clientWins(Model $model, Carbon $clientAt): bool {
        return $clientAt->greaterThanOrEqualTo($model->updated_at);
    }

    private function logConflict(User $user, array $m, string $entity, Model $model, string $resolution, Carbon $clientAt): void {
        SyncConflict::create([
            'user_id' => $user->id,
            'mutation_id' => $m['id'],
            'entity' => $entity,
            'entity_uuid' => $model->uuid,
            'resolution' => $resolution,
            'rule' => 'lww',
            'client_payload' => $m['data'] ?? [],
            'server_snapshot' => SyncSerializer::any($entity, $model),
            'client_updated_at' => $clientAt,
            'server_updated_at' => $model->updated_at,
        ]);
    }

    private function ok(array $m, string $entity, Model $model, ?string $resolution = null): array {
        return array_filter([
            'id' => $m['id'],
            'status' => 'applied',
            'resolution' => $resolution,
            'record' => SyncSerializer::any($entity, $model),
        ], fn ($v) => $v !== null);
    }

    private function find(string $entity, string $uuid): ?Model {
        return match ($entity) {
            'event' => Event::findByUuid($uuid),
            'athlete' => Athlete::findByUuid($uuid),
            'result' => Result::findByUuid($uuid),
            'delay' => ScheduleDelay::findByUuid($uuid),
        };
    }

    private function existingResultFor(array $data): ?Result {
        $event = Event::findByUuid($data['event_uuid'] ?? null);
        $athlete = Athlete::findByUuid($data['athlete_uuid'] ?? null);
        if (!$event || !$athlete) return null;

        return Result::where('event_id', $event->id)->where('athlete_id', $athlete->id)->first();
    }

    private function parentEvent(User $user, array $data): Event {
        $event = Event::findByUuid($data['event_uuid'] ?? null);
        if (!$event) {
            throw ValidationException::withMessages(['event_uuid' => ['Parent event not found']]);
        }
        Gate::forUser($user)->authorize('update', $event);
        return $event;
    }

    private function parentAthlete(Event $event, array $data): Athlete {
        $athlete = Athlete::findByUuid($data['athlete_uuid'] ?? null);
        if (!$athlete || $athlete->event_id !== $event->id) {
            throw ValidationException::withMessages(['athlete_uuid' => ['Athlete not found in this event']]);
        }
        return $athlete;
    }

    private function authorizeFor(User $user, string $entity, Model $model): void {
        $event = $entity === 'event' ? $model : $model->event;
        Gate::forUser($user)->authorize('update', $event);
    }

    /** Client timestamps are trusted for ordering but never allowed in the future. */
    private function clientTime(?string $value): Carbon {
        $now = Carbon::now();
        if (!$value) return $now;
        $t = Carbon::parse($value);
        return $t->greaterThan($now) ? $now : $t;
    }

    private function applyRevisedStart(ScheduleDelay $delay): void {
        $event = $delay->event;
        $total = (int) $event->delays()->sum('delay_minutes');
        $delay->revised_start_time = Carbon::parse($event->start_time)->addMinutes($total)->format('H:i');
        $delay->saveQuietly();
    }
}
