<?php
namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Single source of validation rules for each entity.
 *
 * Both the online REST controllers and the offline sync engine validate with
 * these rules, so a mutation recorded offline can never bypass a check that
 * an online write would have to pass.
 */
class EntityRules {
    public static function event(bool $partial = false): array {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => "$req|string|min:2|max:255",
            'discipline' => 'nullable|string|max:100',
            'event_date' => "$req|date",
            'start_time' => "$req|date_format:H:i,H:i:s",
            'end_time' => 'nullable|date_format:H:i,H:i:s|after:start_time',
            'actual_start_time' => 'nullable|date_format:H:i,H:i:s',
            'venue' => 'nullable|string|max:255',
            'officials_required' => 'nullable|integer|min:0|max:500',
            'officials_assigned' => 'nullable|integer|min:0|max:500',
            'status' => 'sometimes|in:upcoming,running,completed',
        ];
    }

    public static function athlete(int $eventId, ?int $ignoreId = null, bool $partial = false): array {
        return [
            'name' => ($partial ? 'sometimes' : 'required') . '|string|min:2|max:255',
            'bib_number' => [
                'nullable', 'integer', 'min:1', 'max:99999',
                Rule::unique('athletes', 'bib_number')
                    ->where('event_id', $eventId)
                    ->ignore($ignoreId),
            ],
            'age' => 'nullable|integer|min:5|max:100',
            'house' => 'nullable|string|max:50',
        ];
    }

    public static function result(int $eventId, ?int $ignoreId = null): array {
        return [
            'placement' => [
                'nullable', 'integer', 'min:1', 'max:8',
                Rule::unique('results', 'placement')
                    ->where('event_id', $eventId)
                    ->ignore($ignoreId),
            ],
            'time' => 'nullable|string|max:20',
            'wind_speed' => 'nullable|numeric|between:-20,20',
            'notes' => 'nullable|string|max:255',
        ];
    }

    public static function delay(): array {
        return [
            'delay_minutes' => 'required|integer|min:1|max:600',
            'reason' => 'nullable|string|max:255',
        ];
    }
}
