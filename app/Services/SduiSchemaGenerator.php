<?php
namespace App\Services;

use App\Models\Athlete;
use App\Models\Event;
use App\Models\Result;
use App\Models\SyncConflict;
use App\Models\User;

/**
 * Server-Driven UI schema generator (FR8).
 *
 * Turns the analytics engine's findings into an ordered, role-specific
 * dashboard layout. The contract:
 *
 *  - schema_version: the client sends the highest version it can render; the
 *    server answers with min(client, CURRENT_VERSION) and only emits
 *    component types introduced at or below that version.
 *  - components[]: {id, type, priority, props, data{bind, value}}. `bind`
 *    names a selector the client can re-resolve against its local database
 *    while offline; `value` is the server-side snapshot used as a fallback.
 *  - Unknown types are skipped by the client, never crash it.
 *  - Role-scoped: administrative components are never sent to coordinators
 *    ("security by layout"), on top of API authorisation.
 */
class SduiSchemaGenerator {
    public const CURRENT_VERSION = 2;

    /** Component type => schema version that introduced it. */
    public const COMPONENT_VERSIONS = [
        'alert_card' => 1,
        'schedule_list' => 1,
        'stats_row' => 1,
        'standings_card' => 1,
        'action_row' => 1,
        'insight_list' => 2,
        'conflict_summary' => 2,
    ];

    /** Components only an administrator may receive. */
    public const ADMIN_ONLY = ['conflict_summary'];

    public function __construct(
        private EventAnalyticsService $analytics,
        private StandingsService $standings,
    ) {}

    public function generate(User $user, string $role, int $clientVersion = self::CURRENT_VERSION, ?\Carbon\CarbonInterface $now = null): array {
        $version = max(1, min($clientVersion, self::CURRENT_VERSION));
        $analysis = $this->analytics->analyse($user, $now);
        $findings = collect($analysis['findings']);
        $trigger = $analysis['trigger'];

        $components = collect();

        // 1. Alerts: one card per major/critical finding, promoted to the top.
        $urgent = $findings->whereIn('severity', ['critical', 'major'])->take(3)->values();
        foreach ($urgent as $i => $finding) {
            $components->push($this->component("alert_{$i}", 'alert_card', 10 + $i, [
                'severity' => $finding['severity'],
                'title' => $finding['title'],
                'text' => $finding['message'],
                'event_uuid' => $finding['event_uuid'],
                'action' => ['label' => 'Open event', 'route' => 'EventDetail', 'params' => ['eventUuid' => $finding['event_uuid']]],
            ]));
        }

        // 2. Insights: every finding, explained (v2).
        if ($findings->isNotEmpty() || $role === 'admin') {
            $components->push($this->component('insights', 'insight_list', $trigger === 'normal' ? 60 : 20, [
                'title' => $role === 'admin' ? 'Venue & resource insights' : 'AI insights',
                'empty_text' => 'No issues detected across today\'s events.',
            ], 'analysis.findings', $findings->take(8)->values()->all()));
        }

        // 3. Today's schedule; delayed events highlighted when a delay is active.
        $components->push($this->component('schedule', 'schedule_list', $trigger === 'delay_detected' ? 15 : 40, [
            'title' => "Today's Events",
            'highlight' => $trigger === 'delay_detected' ? 'delayed' : null,
        ], 'events.today', $this->todaySchedule($user, $analysis)));

        // 4. Summary statistics: first in a calm state, demoted during incidents.
        $components->push($this->component('stats', 'stats_row', $trigger === 'normal' ? 5 : 50, [
            'items' => [
                ['key' => 'events.count', 'label' => 'Events'],
                ['key' => 'athletes.count', 'label' => 'Athletes'],
                ['key' => 'results.entered', 'label' => 'Results'],
                ['key' => 'sync.pending', 'label' => 'Pending sync'],
            ],
        ], 'stats.summary', $this->stats($user)));

        // 5. House standings / medal tally.
        $eventIds = Event::visibleTo($user)->pluck('id');
        $components->push($this->component('standings', 'standings_card', 70, [
            'title' => 'Medal Table',
            'limit' => 4,
        ], 'standings.overall', $this->standings->overall($eventIds)));

        // 6. Conflict audit summary (admin only, v2).
        if ($role === 'admin') {
            $components->push($this->component('conflicts', 'conflict_summary', 80, [
                'title' => 'Sync conflicts (24h)',
            ], 'sync.conflicts', [
                'count' => SyncConflict::where('created_at', '>=', now()->subDay())->count(),
                'server_wins' => SyncConflict::where('created_at', '>=', now()->subDay())->where('resolution', 'server_wins')->count(),
            ]));
        }

        // 7. Quick actions, tailored to role.
        $actions = ['new_event', 'view_events', 'sync_now'];
        if ($role === 'admin') $actions[] = 'view_sync_activity';
        $components->push($this->component('actions', 'action_row', 90, ['actions' => $actions]));

        $components = $components
            ->filter(fn ($c) => self::COMPONENT_VERSIONS[$c['type']] <= $version)
            ->filter(fn ($c) => $role === 'admin' || !in_array($c['type'], self::ADMIN_ONLY, true))
            ->sortBy('priority')
            ->values();

        return [
            'schema_version' => $version,
            'role' => $role,
            'generated_at' => $analysis['generated_at'],
            'reason' => $trigger,
            'analysis' => [
                'trigger' => $trigger,
                'highest_severity' => $analysis['highest_severity'],
                'metrics' => $analysis['metrics'],
                'finding_count' => $findings->count(),
            ],
            'components' => $components->all(),
            'cache' => ['ttl_minutes' => 30, 'offline_render' => true],
        ];
    }

    private function component(string $id, string $type, int $priority, array $props = [], ?string $bind = null, mixed $value = null): array {
        $component = compact('id', 'type', 'priority', 'props');
        if ($bind !== null) {
            $component['data'] = ['bind' => $bind, 'value' => $value];
        }
        return $component;
    }

    private function todaySchedule(User $user, array $analysis): array {
        return Event::visibleTo($user)
            ->today()
            ->orderBy('start_time')
            ->get()
            ->map(fn (Event $e) => [
                'uuid' => $e->uuid,
                'name' => $e->name,
                'venue' => $e->venue,
                'start_time' => substr((string) $e->start_time, 0, 5),
                'status' => $e->status,
                'delay_minutes' => $analysis['event_delays'][$e->id] ?? 0,
            ])->all();
    }

    private function stats(User $user): array {
        $eventIds = Event::visibleTo($user)->pluck('id');

        return [
            'events.count' => $eventIds->count(),
            'athletes.count' => Athlete::whereIn('event_id', $eventIds)->count(),
            'results.entered' => Result::whereIn('event_id', $eventIds)->count(),
            'sync.pending' => 0, // resolved on the device from its local queue
        ];
    }
}
