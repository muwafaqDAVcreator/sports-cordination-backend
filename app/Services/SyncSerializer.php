<?php
namespace App\Services;

use App\Models\Athlete;
use App\Models\Event;
use App\Models\Result;
use App\Models\ScheduleDelay;

/**
 * Flat, uuid-addressed representation of each entity as the mobile client
 * stores it. Parent references are carried as uuids so the client never needs
 * to know server ids to relate records it created offline.
 */
class SyncSerializer {
    public static function event(Event $e): array {
        return [
            'id' => $e->id,
            'uuid' => $e->uuid,
            'name' => $e->name,
            'discipline' => $e->discipline,
            'event_date' => $e->event_date?->toDateString(),
            'start_time' => self::hm($e->start_time),
            'end_time' => self::hm($e->end_time),
            'actual_start_time' => self::hm($e->actual_start_time),
            'venue' => $e->venue,
            'officials_required' => (int) $e->officials_required,
            'officials_assigned' => (int) $e->officials_assigned,
            'status' => $e->status,
            'created_by' => $e->created_by,
            'updated_at' => $e->updated_at?->toIso8601ZuluString('millisecond'),
        ];
    }

    public static function athlete(Athlete $a): array {
        return [
            'id' => $a->id,
            'uuid' => $a->uuid,
            'event_uuid' => $a->event?->uuid,
            'name' => $a->name,
            'bib_number' => $a->bib_number,
            'age' => $a->age,
            'house' => $a->house,
            'updated_at' => $a->updated_at?->toIso8601ZuluString('millisecond'),
        ];
    }

    public static function result(Result $r): array {
        return [
            'id' => $r->id,
            'uuid' => $r->uuid,
            'event_uuid' => $r->event?->uuid,
            'athlete_uuid' => $r->athlete?->uuid,
            'placement' => $r->placement,
            'time' => $r->time,
            'wind_speed' => $r->wind_speed,
            'notes' => $r->notes,
            'updated_at' => $r->updated_at?->toIso8601ZuluString('millisecond'),
        ];
    }

    public static function delay(ScheduleDelay $d): array {
        return [
            'id' => $d->id,
            'uuid' => $d->uuid,
            'event_uuid' => $d->event?->uuid,
            'delay_minutes' => $d->delay_minutes,
            'reason' => $d->reason,
            'severity' => $d->severity,
            'revised_start_time' => self::hm($d->revised_start_time),
            'created_at' => $d->created_at?->toIso8601ZuluString('millisecond'),
            'updated_at' => $d->updated_at?->toIso8601ZuluString('millisecond'),
        ];
    }

    public static function any(string $entity, $model): array {
        return match ($entity) {
            'event' => self::event($model),
            'athlete' => self::athlete($model),
            'result' => self::result($model),
            'delay' => self::delay($model),
        };
    }

    private static function hm(?string $time): ?string {
        return $time ? substr($time, 0, 5) : null;
    }
}
