<?php

namespace Tests\Feature;

use App\Models\Athlete;
use App\Models\Event;
use App\Models\Result;
use App\Models\SyncConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Module 6 - synchronization engine (FR4-FR6, offline scenarios S3-S7). */
class SyncTest extends TestCase
{
    use RefreshDatabase;

    private function upload(array $mutations)
    {
        return $this->postJson('/api/sync/upload', ['mutations' => $mutations]);
    }

    public function test_s3_offline_session_uploads_in_order_with_uuid_references(): void
    {
        Sanctum::actingAs($user = $this->makeUser());
        [$e, $a, $r, $d] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        // Event, athlete, result and delay all created offline; children refer
        // to parents only by the uuids the client generated.
        $res = $this->upload([
            $this->mutation('event', 'create', $e, ['name' => 'Offline 100m', 'event_date' => now()->toDateString(), 'start_time' => '09:00', 'venue' => 'Main Track']),
            $this->mutation('athlete', 'create', $a, ['event_uuid' => $e, 'name' => 'Kamal Perera', 'house' => 'Red']),
            $this->mutation('result', 'create', $r, ['event_uuid' => $e, 'athlete_uuid' => $a, 'placement' => 1, 'time' => '00:10.95']),
            $this->mutation('delay', 'create', $d, ['event_uuid' => $e, 'delay_minutes' => 15, 'reason' => 'Rain']),
        ])->assertOk();

        $this->assertSame(['applied', 'applied', 'applied', 'applied'], array_column($res->json('data.results'), 'status'));
        $this->assertSame($e, Event::first()->uuid);
        $this->assertSame($user->id, Event::first()->created_by);
        $this->assertSame(1, Athlete::first()->bib_number, 'bib generated on sync');
        $this->assertSame(Athlete::first()->id, Result::first()->athlete_id);
        $res->assertJsonPath('data.results.3.record.severity', 'major')
            ->assertJsonPath('data.results.3.record.revised_start_time', '09:15')
            ->assertJsonPath('data.results.1.record.event_uuid', $e);
    }

    public function test_s5_lww_server_wins_when_server_write_is_newer_and_is_audited(): void
    {
        Sanctum::actingAs($user = $this->makeUser());
        $event = $this->makeEvent($user);
        $athlete = $this->makeAthlete($event, ['name' => 'Original']);
        $base = $athlete->updated_at->copy();

        // Device B edits online at T+10min (server now holds the newer write).
        $this->travel(10)->minutes();
        $athlete->update(['name' => 'Edited online']);

        // Device A's offline edit was made at T+5min, based on the old version.
        $res = $this->upload([$this->mutation('athlete', 'update', $athlete->uuid, ['name' => 'Edited offline'], [
            'base_updated_at' => $base->toIso8601ZuluString(),
            'client_updated_at' => $base->copy()->addMinutes(5)->toIso8601ZuluString(),
        ])])->assertOk();

        $res->assertJsonPath('data.results.0.status', 'conflict')
            ->assertJsonPath('data.results.0.resolution', 'server_wins')
            ->assertJsonPath('data.results.0.record.name', 'Edited online');
        $this->assertSame('Edited online', $athlete->fresh()->name);

        $log = SyncConflict::sole();
        $this->assertSame('server_wins', $log->resolution);
        $this->assertSame('lww', $log->rule);
        $this->assertSame('Edited offline', $log->client_payload['name']);
        $this->assertSame('Edited online', $log->server_snapshot['name']);
    }

    public function test_s5_lww_client_wins_when_offline_write_is_newer_and_is_audited(): void
    {
        Sanctum::actingAs($user = $this->makeUser());
        $event = $this->makeEvent($user);
        $athlete = $this->makeAthlete($event, ['house' => 'Red']);
        $base = $athlete->updated_at->copy();

        $this->travel(5)->minutes();
        $athlete->update(['house' => 'Blue']);        // online edit at T+5
        $this->travel(5)->minutes();                   // offline edit at T+10

        $res = $this->upload([$this->mutation('athlete', 'update', $athlete->uuid, ['house' => 'Green'], [
            'base_updated_at' => $base->toIso8601ZuluString(),
        ])])->assertOk();

        $res->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.resolution', 'client_wins');
        $this->assertSame('Green', $athlete->fresh()->house);
        $this->assertSame('client_wins', SyncConflict::sole()->resolution);
    }

    public function test_update_without_concurrent_change_is_not_a_conflict(): void
    {
        Sanctum::actingAs($user = $this->makeUser());
        $event = $this->makeEvent($user);

        $this->upload([$this->mutation('event', 'update', $event->uuid, ['status' => 'running'], [
            'base_updated_at' => $event->updated_at->toIso8601ZuluString(),
        ])])->assertJsonPath('data.results.0.status', 'applied');

        $this->assertSame('running', $event->fresh()->status);
        $this->assertSame(0, SyncConflict::count());
    }

    public function test_s6_retried_upload_is_idempotent(): void
    {
        Sanctum::actingAs($user = $this->makeUser());
        $event = $this->makeEvent($user);
        $m = $this->mutation('athlete', 'create', (string) Str::uuid(), ['event_uuid' => $event->uuid, 'name' => 'Once Only']);

        $this->upload([$m])->assertJsonPath('data.results.0.status', 'applied');
        // Connection dropped before the client saw the response; it retries.
        $this->upload([$m])
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.replayed', true);

        $this->assertSame(1, Athlete::where('name', 'Once Only')->count());
    }

    public function test_s7_invalid_mutation_is_rejected_with_reasons_and_does_not_block_batch(): void
    {
        Sanctum::actingAs($user = $this->makeUser());
        $event = $this->makeEvent($user);
        $a = $this->makeAthlete($event);
        $b = $this->makeAthlete($event);
        Result::create(['event_id' => $event->id, 'athlete_id' => $a->id, 'placement' => 1]);

        $res = $this->upload([
            // Placement 1 is already taken in this event.
            $this->mutation('result', 'create', (string) Str::uuid(), ['event_uuid' => $event->uuid, 'athlete_uuid' => $b->uuid, 'placement' => 1]),
            $this->mutation('athlete', 'create', (string) Str::uuid(), ['event_uuid' => $event->uuid, 'name' => 'Still applied']),
        ])->assertOk();

        $res->assertJsonPath('data.results.0.status', 'rejected')
            ->assertJsonPath('data.results.1.status', 'applied');
        $this->assertArrayHasKey('placement', $res->json('data.results.0.errors'));
    }

    public function test_offline_results_from_two_devices_for_same_athlete_merge_by_lww(): void
    {
        Sanctum::actingAs($user = $this->makeUser());
        $event = $this->makeEvent($user);
        $athlete = $this->makeAthlete($event);
        $first = Result::create(['event_id' => $event->id, 'athlete_id' => $athlete->id, 'placement' => 2]);

        $this->travel(1)->minutes();
        $res = $this->upload([$this->mutation('result', 'create', (string) Str::uuid(), [
            'event_uuid' => $event->uuid, 'athlete_uuid' => $athlete->uuid, 'placement' => 3,
        ])])->assertOk();

        $res->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.resolution', 'client_wins')
            ->assertJsonPath('data.results.0.record.uuid', $first->uuid); // client adopts server uuid
        $this->assertSame(1, Result::count());
        $this->assertSame(3, $first->fresh()->placement);
    }

    public function test_delete_is_idempotent_and_protects_athletes_with_results(): void
    {
        Sanctum::actingAs($user = $this->makeUser());
        $event = $this->makeEvent($user);
        $withResult = $this->makeAthlete($event);
        Result::create(['event_id' => $event->id, 'athlete_id' => $withResult->id, 'placement' => 1]);

        $this->upload([
            $this->mutation('athlete', 'delete', (string) Str::uuid()),   // never existed
            $this->mutation('athlete', 'delete', $withResult->uuid),
        ])->assertJsonPath('data.results.0.status', 'applied')
          ->assertJsonPath('data.results.1.status', 'rejected');
    }

    public function test_cannot_sync_into_another_coordinators_event(): void
    {
        $owner = $this->makeUser();
        $event = $this->makeEvent($owner);
        Sanctum::actingAs($this->makeUser());

        $this->upload([$this->mutation('athlete', 'create', (string) Str::uuid(), ['event_uuid' => $event->uuid, 'name' => 'Intruder'])])
            ->assertJsonPath('data.results.0.status', 'rejected');
        $this->assertSame(0, Athlete::count());
    }

    public function test_malformed_mutation_is_rejected(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->upload([['id' => 'not-a-uuid', 'entity' => 'stadium', 'op' => 'explode']])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'rejected');
    }

    public function test_download_returns_uuid_addressed_snapshot_of_visible_records_only(): void
    {
        $user = $this->makeUser();
        $mine = $this->makeEvent($user);
        $athlete = $this->makeAthlete($mine);
        Result::create(['event_id' => $mine->id, 'athlete_id' => $athlete->id, 'placement' => 1]);
        $this->makeEvent($this->makeUser(), ['name' => 'Not mine']);
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/sync/download')->assertOk();

        $res->assertJsonCount(1, 'data.events')
            ->assertJsonPath('data.events.0.uuid', $mine->uuid)
            ->assertJsonPath('data.athletes.0.event_uuid', $mine->uuid)
            ->assertJsonPath('data.results.0.athlete_uuid', $athlete->uuid);
        $this->assertNotNull($res->json('data.server_time'));
    }

    public function test_conflict_log_is_visible_to_owner(): void
    {
        $user = $this->makeUser();
        SyncConflict::create([
            'user_id' => $user->id, 'mutation_id' => (string) Str::uuid(), 'entity' => 'athlete',
            'entity_uuid' => (string) Str::uuid(), 'resolution' => 'server_wins',
            'client_payload' => ['name' => 'x'], 'server_snapshot' => ['name' => 'y'],
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/sync/conflicts')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/sync/status')->assertJsonPath('data.conflicts_24h', 1);
    }
}
