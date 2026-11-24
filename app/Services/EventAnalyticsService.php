<?php
namespace App\Services;

use App\Models\Event;
use App\Models\ScheduleDelay;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Analytics engine (FR7): evaluates live event progression and produces
 * explainable findings that drive SDUI dashboard generation.
 *
 * This is the "statistical tier" chosen in the interim report: transparent
 * threshold and z-score rules that need no training data and whose output can
 * be stated to a coordinator in one sentence ("100m Final is 12 min behind
 * schedule"). Detectors:
 *
 *  1. Schedule delay   - logged delays, late actual starts, and events still
 *                        "upcoming" after their start time (inferred delay).
 *  2. Delay anomaly    - z-score of each event's delay against today's delays.
 *  3. Knock-on risk    - a delayed event that now overruns the next event at
 *                        the same venue.
 *  4. Venue conflict   - two events scheduled at one venue at the same time.
 *  5. Resource shortfall - officials assigned below officials required.
 *  6. Results backlog  - completed events still missing results.
 */
class EventAnalyticsService {
    public const GRACE_MINUTES = 5;
    public const Z_THRESHOLD = 1.5;

    private const SEVERITY_RANK = ['info' => 0, 'minor' => 1, 'major' => 2, 'critical' => 3];

    public function analyse(User $user, ?CarbonInterface $now = null): array {
        $now = $now ? Carbon::instance($now) : Carbon::now();

        $events = Event::visibleTo($user)
            ->whereDate('event_date', $now->toDateString())
            ->with(['delays'])
            ->withCount(['athletes', 'results'])
            ->orderBy('start_time')
            ->get();

        $delays = $events->mapWithKeys(fn (Event $e) => [$e->id => $this->effectiveDelay($e, $now)]);

        $findings = collect()
            ->merge($this->delayFindings($events, $delays))
            ->merge($this->anomalyFindings($events, $delays))
            ->merge($this->knockOnFindings($events, $delays))
            ->merge($this->venueConflictFindings($events))
            ->merge($this->resourceFindings($events, $now))
            ->merge($this->backlogFindings($events))
            ->sortByDesc(fn ($f) => self::SEVERITY_RANK[$f['severity']])
            ->values();

        $delayValues = $delays->filter(fn ($d) => $d > 0);

        return [
            'generated_at' => $now->toIso8601String(),
            'date' => $now->toDateString(),
            'trigger' => $this->trigger($findings),
            'highest_severity' => $findings->first()['severity'] ?? 'none',
            'metrics' => [
                'events_today' => $events->count(),
                'running' => $events->where('status', 'running')->count(),
                'completed' => $events->where('status', 'completed')->count(),
                'delayed_events' => $delayValues->count(),
                'mean_delay_minutes' => round($delayValues->avg() ?? 0, 1),
                'max_delay_minutes' => (int) ($delayValues->max() ?? 0),
            ],
            'event_delays' => $delays->all(),
            'findings' => $findings->all(),
        ];
    }

    /** Minutes an event is behind schedule right now. */
    public function effectiveDelay(Event $event, CarbonInterface $now): int {
        $scheduled = $this->at($event, $event->start_time);
        $logged = $event->totalDelayMinutes();

        $actual = 0;
        if ($event->actual_start_time) {
            $actual = max(0, (int) round($scheduled->diffInMinutes($this->at($event, $event->actual_start_time), false)));
        }

        $inferred = 0;
        if ($event->status === 'upcoming') {
            $late = (int) floor($scheduled->diffInMinutes($now, false));
            if ($late > self::GRACE_MINUTES) {
                $inferred = $late;
            }
        }

        return max($logged, $actual, $inferred);
    }

    private function delayFindings(Collection $events, Collection $delays): Collection {
        return $events
            ->filter(fn (Event $e) => $delays[$e->id] > 0 && $e->status !== 'completed')
            ->map(function (Event $e) use ($delays) {
                $minutes = $delays[$e->id];
                $reason = $e->delays->first()?->reason;

                return $this->finding(
                    'schedule_delay',
                    ScheduleDelay::severityFor($minutes),
                    $e,
                    "{$e->name} delayed {$minutes} min",
                    "{$e->name} is {$minutes} minutes behind schedule" . ($e->venue ? " at {$e->venue}" : '') . ($reason ? " ({$reason})." : '.'),
                    ['delay_minutes' => $minutes],
                );
            })->values();
    }

    /** z-score of each delay against today's distribution (needs >= 3 events). */
    private function anomalyFindings(Collection $events, Collection $delays): Collection {
        $values = $events->map(fn (Event $e) => $delays[$e->id])->values();
        if ($values->count() < 3) return collect();

        $mean = $values->avg();
        $std = sqrt($values->map(fn ($v) => ($v - $mean) ** 2)->sum() / $values->count());
        if ($std < 1) return collect();

        return $events
            ->filter(fn (Event $e) => $e->status !== 'completed')
            ->map(fn (Event $e) => [$e, ($delays[$e->id] - $mean) / $std])
            ->filter(fn ($pair) => $pair[1] >= self::Z_THRESHOLD && $delays[$pair[0]->id] >= self::GRACE_MINUTES)
            ->map(fn ($pair) => $this->finding(
                'delay_anomaly',
                'major',
                $pair[0],
                "Unusual delay: {$pair[0]->name}",
                sprintf('%s is delayed far more than other events today (z = %.1f, mean delay %.0f min).', $pair[0]->name, $pair[1], $mean),
                ['z_score' => round($pair[1], 2), 'mean_delay' => round($mean, 1)],
            ))->values();
    }

    /** A delayed event whose shifted end now overlaps the next event at the same venue. */
    private function knockOnFindings(Collection $events, Collection $delays): Collection {
        $out = collect();

        foreach ($events->whereNotNull('venue')->groupBy('venue') as $venueEvents) {
            $ordered = $venueEvents->sortBy('start_time')->values();

            for ($i = 0; $i < $ordered->count() - 1; $i++) {
                $current = $ordered[$i];
                $next = $ordered[$i + 1];
                $delay = $delays[$current->id];
                if ($delay <= 0 || $current->status === 'completed') continue;

                $end = $this->at($current, $current->end_time ?? $current->start_time)->addMinutes($delay);
                $nextStart = $this->at($next, $next->start_time);

                if ($end->greaterThan($nextStart)) {
                    $overlap = (int) $nextStart->diffInMinutes($end);
                    $out->push($this->finding(
                        'knock_on_risk',
                        $overlap >= 15 ? 'major' : 'minor',
                        $next,
                        "{$next->name} at risk",
                        "{$current->name} now overruns into {$next->name} at {$next->venue} by {$overlap} min.",
                        ['caused_by' => $current->uuid, 'overlap_minutes' => $overlap],
                    ));
                }
            }
        }

        return $out;
    }

    /** Two events booked at the same venue with overlapping scheduled windows. */
    private function venueConflictFindings(Collection $events): Collection {
        $out = collect();

        foreach ($events->whereNotNull('venue')->groupBy('venue') as $venue => $venueEvents) {
            $list = $venueEvents->values();
            for ($i = 0; $i < $list->count(); $i++) {
                for ($j = $i + 1; $j < $list->count(); $j++) {
                    [$a, $b] = [$list[$i], $list[$j]];
                    $aStart = $this->at($a, $a->start_time);
                    $aEnd = $this->at($a, $a->end_time ?? $a->start_time)->max($aStart->copy()->addMinutes(30));
                    $bStart = $this->at($b, $b->start_time);
                    $bEnd = $this->at($b, $b->end_time ?? $b->start_time)->max($bStart->copy()->addMinutes(30));

                    if ($aStart->lessThan($bEnd) && $bStart->lessThan($aEnd)) {
                        $out->push($this->finding(
                            'venue_conflict',
                            'major',
                            $b,
                            "Venue clash at {$venue}",
                            "{$a->name} and {$b->name} are both scheduled at {$venue} at overlapping times.",
                            ['other_event' => $a->uuid, 'venue' => $venue],
                        ));
                    }
                }
            }
        }

        return $out;
    }

    /** Officials assigned below requirement for events that have not finished. */
    private function resourceFindings(Collection $events, Carbon $now): Collection {
        return $events
            ->filter(fn (Event $e) => $e->status !== 'completed' && $e->officials_assigned < $e->officials_required)
            ->map(function (Event $e) use ($now) {
                $short = $e->officials_required - $e->officials_assigned;
                $minutesToStart = (int) $now->diffInMinutes($this->at($e, $e->start_time), false);
                $imminent = $minutesToStart <= 60;
                $severity = ($short >= 2 && $imminent) ? 'critical' : (($short >= 2 || $imminent) ? 'major' : 'minor');

                return $this->finding(
                    'resource_shortfall',
                    $severity,
                    $e,
                    "{$e->name}: {$short} official(s) short",
                    "{$e->name} has {$e->officials_assigned} of {$e->officials_required} officials assigned" . ($e->venue ? " at {$e->venue}." : '.'),
                    ['shortfall' => $short, 'minutes_to_start' => $minutesToStart],
                );
            })->values();
    }

    /** Completed events whose results have not been fully entered. */
    private function backlogFindings(Collection $events): Collection {
        return $events
            ->filter(fn (Event $e) => $e->status === 'completed' && $e->athletes_count > 0 && $e->results_count < min(3, $e->athletes_count))
            ->map(fn (Event $e) => $this->finding(
                'results_backlog',
                'minor',
                $e,
                "Results missing: {$e->name}",
                "{$e->name} has finished but only {$e->results_count} result(s) are entered.",
                ['results' => $e->results_count, 'athletes' => $e->athletes_count],
            ))->values();
    }

    private function trigger(Collection $findings): string {
        $types = $findings->pluck('type');
        if ($types->intersect(['schedule_delay', 'delay_anomaly', 'knock_on_risk'])->isNotEmpty()) return 'delay_detected';
        if ($types->intersect(['venue_conflict', 'resource_shortfall'])->isNotEmpty()) return 'bottleneck_detected';
        if ($types->contains('results_backlog')) return 'results_backlog';
        return 'normal';
    }

    private function finding(string $type, string $severity, Event $event, string $title, string $message, array $metric = []): array {
        return [
            'type' => $type,
            'severity' => $severity,
            'event_id' => $event->id,
            'event_uuid' => $event->uuid,
            'event_name' => $event->name,
            'title' => $title,
            'message' => $message,
            'metric' => $metric,
        ];
    }

    private function at(Event $event, string $time): Carbon {
        return Carbon::parse($event->event_date->toDateString() . ' ' . $time);
    }
}
