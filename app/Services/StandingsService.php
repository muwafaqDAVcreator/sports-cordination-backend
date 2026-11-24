<?php
namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Collection;

/**
 * Computes rankings, house points and medal tallies (FR3).
 *
 * Scoring: 1st = 5 points (gold), 2nd = 3 points (silver), 3rd = 1 point
 * (bronze). The mobile client implements the identical rule in
 * src/logic/standings.js so standings stay correct while offline.
 */
class StandingsService {
    public const POINTS = [1 => 5, 2 => 3, 3 => 1];
    public const MEDALS = [1 => 'gold', 2 => 'silver', 3 => 'bronze'];

    /** Standings for a single event. */
    public function forEvent(Event $event): array {
        $results = $event->results()->with('athlete')->whereNotNull('placement')->orderBy('placement')->get();

        $tally = $this->tally($results);

        return [
            'rankings' => $results->values(),
            'house_points' => collect($tally)->mapWithKeys(fn ($row) => [$row['house'] => $row['points']])->all(),
            'medal_table' => array_values($tally),
        ];
    }

    /** Overall medal table across a set of events. */
    public function overall(Collection $eventIds): array {
        $results = \App\Models\Result::with('athlete')
            ->whereIn('event_id', $eventIds)
            ->whereNotNull('placement')
            ->get();

        return array_values($this->tally($results));
    }

    /**
     * @param  Collection  $results  results with athlete loaded
     * @return array<string, array{house:string,gold:int,silver:int,bronze:int,points:int}>
     */
    private function tally(Collection $results): array {
        $table = [];

        foreach ($results as $result) {
            $house = $result->athlete->house ?: 'Unassigned';
            $table[$house] ??= ['house' => $house, 'gold' => 0, 'silver' => 0, 'bronze' => 0, 'points' => 0];

            if (isset(self::MEDALS[$result->placement])) {
                $table[$house][self::MEDALS[$result->placement]]++;
            }
            $table[$house]['points'] += self::POINTS[$result->placement] ?? 0;
        }

        // Olympic ordering: gold, then silver, then bronze, then points.
        uasort($table, fn ($a, $b) => [$b['gold'], $b['silver'], $b['bronze'], $b['points']]
            <=> [$a['gold'], $a['silver'], $a['bronze'], $a['points']]);

        return $table;
    }
}
