<?php

namespace Tests\Feature;

use App\Models\ScheduleDelay;
use App\Services\EventAnalyticsService;
use App\Services\SduiSchemaGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Module 6 - analytics engine (FR7) and Server-Driven UI (FR8, FR9). */
class AnalyticsSduiTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse(now()->toDateString() . ' 10:00');
        Carbon::setTestNow($this->now);
    }

    private function analyse($user): array
    {
        return app(EventAnalyticsService::class)->analyse($user, $this->now);
    }

    private function types(array $analysis): array
    {
        return array_column($analysis['findings'], 'type');
    }

    public function test_quiet_meet_produces_no_findings(): void
    {
        $user = $this->makeUser();
        $this->makeEvent($user, ['start_time' => '11:00', 'end_time' => '11:30']);

        $a = $this->analyse($user);
        $this->assertSame('normal', $a['trigger']);
        $this->assertSame([], $a['findings']);
    }

    public function test_logged_delay_is_detected_with_severity_band(): void
    {
        $user = $this->makeUser();
        $event = $this->makeEvent($user, ['name' => '100m Final', 'start_time' => '09:55', 'end_time' => '10:05', 'status' => 'running']);
        ScheduleDelay::create(['event_id' => $event->id, 'delay_minutes' => 12, 'severity' => 'major', 'reason' => 'False start']);

        $a = $this->analyse($user);
        $this->assertSame('delay_detected', $a['trigger']);
        $this->assertSame('schedule_delay', $a['findings'][0]['type']);
        $this->assertSame('major', $a['findings'][0]['severity']);
        $this->assertStringContainsString('12 minutes behind', $a['findings'][0]['message']);
    }

    public function test_event_not_started_after_grace_period_infers_delay(): void
    {
        $user = $this->makeUser();
        $this->makeEvent($user, ['start_time' => '09:20', 'end_time' => '11:00', 'status' => 'upcoming']);

        $a = $this->analyse($user);
        $this->assertSame(40, $a['metrics']['max_delay_minutes']);
        $this->assertSame('critical', $a['findings'][0]['severity']);
    }

    public function test_delay_overrunning_next_event_at_same_venue_flags_knock_on_risk(): void
    {
        $user = $this->makeUser();
        $first = $this->makeEvent($user, ['name' => '100m Final', 'start_time' => '09:50', 'end_time' => '10:10', 'status' => 'running']);
        $this->makeEvent($user, ['name' => '200m Heats', 'start_time' => '10:15', 'end_time' => '10:45']);
        ScheduleDelay::create(['event_id' => $first->id, 'delay_minutes' => 20, 'severity' => 'major']);

        $knock = collect($this->analyse($user)['findings'])->firstWhere('type', 'knock_on_risk');
        $this->assertNotNull($knock);
        $this->assertSame('200m Heats', $knock['event_name']);
        $this->assertSame(15, $knock['metric']['overlap_minutes']);
    }

    public function test_venue_clash_and_resource_shortfall_are_bottlenecks(): void
    {
        $user = $this->makeUser();
        $this->makeEvent($user, ['name' => 'Shot Put', 'venue' => 'Field A', 'start_time' => '10:30', 'end_time' => '11:30', 'officials_required' => 3, 'officials_assigned' => 1]);
        $this->makeEvent($user, ['name' => 'Discus', 'venue' => 'Field A', 'start_time' => '11:00', 'end_time' => '12:00']);

        $a = $this->analyse($user);
        $this->assertSame('bottleneck_detected', $a['trigger']);
        $this->assertContains('venue_conflict', $this->types($a));
        $shortfall = collect($a['findings'])->firstWhere('type', 'resource_shortfall');
        $this->assertSame(2, $shortfall['metric']['shortfall']);
        $this->assertSame('critical', $shortfall['severity'], '2 short and starting within the hour');
    }

    public function test_statistical_anomaly_uses_z_score(): void
    {
        $user = $this->makeUser();
        foreach (['A' => '08:00', 'B' => '08:30', 'C' => '09:00', 'D' => '09:30'] as $name => $start) {
            $this->makeEvent($user, ['name' => $name, 'start_time' => $start, 'end_time' => $start, 'venue' => $name, 'status' => 'running']);
        }
        $outlier = $this->makeEvent($user, ['name' => 'Outlier', 'start_time' => '09:45', 'end_time' => '09:50', 'venue' => 'X', 'status' => 'running']);
        ScheduleDelay::create(['event_id' => $outlier->id, 'delay_minutes' => 40, 'severity' => 'critical']);

        $anomaly = collect($this->analyse($user)['findings'])->firstWhere('type', 'delay_anomaly');
        $this->assertNotNull($anomaly);
        $this->assertSame('Outlier', $anomaly['event_name']);
        $this->assertGreaterThanOrEqual(1.5, $anomaly['metric']['z_score']);
    }

    public function test_completed_event_missing_results_is_a_backlog(): void
    {
        $user = $this->makeUser();
        $event = $this->makeEvent($user, ['start_time' => '08:00', 'end_time' => '09:00', 'status' => 'completed']);
        $this->makeAthlete($event);
        $this->makeAthlete($event);

        $this->assertContains('results_backlog', $this->types($this->analyse($user)));
    }

    // ---------------------------------------------------------------- SDUI

    public function test_calm_state_layout_leads_with_statistics(): void
    {
        $user = $this->makeUser();
        $this->makeEvent($user, ['start_time' => '11:00', 'end_time' => '11:30']);
        Sanctum::actingAs($user);

        $schema = $this->getJson('/api/dashboards/coordinator')->assertOk()->json('data');

        $this->assertSame(SduiSchemaGenerator::CURRENT_VERSION, $schema['schema_version']);
        $this->assertSame('normal', $schema['reason']);
        $this->assertSame('stats_row', $schema['components'][0]['type']);
        $this->assertNotContains('alert_card', array_column($schema['components'], 'type'));
        $this->assertTrue($schema['cache']['offline_render']);
    }

    public function test_delay_promotes_alert_and_schedule_above_statistics(): void
    {
        $user = $this->makeUser();
        $event = $this->makeEvent($user, ['name' => '100m Final', 'start_time' => '09:55', 'end_time' => '10:05', 'status' => 'running']);
        ScheduleDelay::create(['event_id' => $event->id, 'delay_minutes' => 12, 'severity' => 'major']);
        Sanctum::actingAs($user);

        $schema = $this->getJson('/api/dashboards/coordinator')->json('data');
        $types = array_column($schema['components'], 'type');

        $this->assertSame('delay_detected', $schema['reason']);
        $this->assertSame('alert_card', $types[0]);
        $this->assertLessThan(array_search('stats_row', $types), array_search('schedule_list', $types));
        $this->assertStringContainsString('100m Final', $schema['components'][0]['props']['title']);

        $schedule = collect($schema['components'])->firstWhere('type', 'schedule_list');
        $this->assertSame('delayed', $schedule['props']['highlight']);
        $this->assertSame('events.today', $schedule['data']['bind']);
        $this->assertSame(12, $schedule['data']['value'][0]['delay_minutes']);
    }

    public function test_older_clients_receive_only_components_they_support(): void
    {
        $user = $this->makeUser();
        $this->makeEvent($user, ['start_time' => '09:00', 'status' => 'upcoming']); // inferred delay -> insights
        Sanctum::actingAs($user);

        $v1 = $this->getJson('/api/dashboards/coordinator?schema_version=1')->json('data');
        $this->assertSame(1, $v1['schema_version']);
        $this->assertNotContains('insight_list', array_column($v1['components'], 'type'));

        $v2 = $this->getJson('/api/dashboards/coordinator?schema_version=2')->json('data');
        $this->assertContains('insight_list', array_column($v2['components'], 'type'));
    }

    public function test_security_by_layout_coordinators_never_receive_admin_components(): void
    {
        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/dashboards/admin')->assertForbidden();
        $coordinator = $this->getJson('/api/dashboards/coordinator')->json('data');
        $this->assertNotContains('conflict_summary', array_column($coordinator['components'], 'type'));

        Sanctum::actingAs($this->makeUser(['role' => 'admin']));
        $admin = $this->getJson('/api/dashboards/admin')->assertOk()->json('data');
        $this->assertContains('conflict_summary', array_column($admin['components'], 'type'));
        $this->assertContains('view_sync_activity', collect($admin['components'])->firstWhere('type', 'action_row')['props']['actions']);
    }

    public function test_every_component_type_emitted_is_registered_and_versioned(): void
    {
        $user = $this->makeUser(['role' => 'admin']);
        $event = $this->makeEvent($user, ['start_time' => '09:55', 'status' => 'running', 'officials_required' => 2]);
        ScheduleDelay::create(['event_id' => $event->id, 'delay_minutes' => 35, 'severity' => 'critical']);

        $schema = app(SduiSchemaGenerator::class)->generate($user, 'admin', 2, $this->now);
        foreach ($schema['components'] as $c) {
            $this->assertArrayHasKey($c['type'], SduiSchemaGenerator::COMPONENT_VERSIONS);
            $this->assertArrayHasKey('id', $c);
            $this->assertArrayHasKey('priority', $c);
        }
        $priorities = array_column($schema['components'], 'priority');
        $sorted = $priorities;
        sort($sorted);
        $this->assertSame($sorted, $priorities, 'components are ordered by priority');
    }
}
