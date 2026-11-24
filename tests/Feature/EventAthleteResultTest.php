<?php

namespace Tests\Feature;

use App\Models\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Modules 2-4 - events, athletes, results and standings (T10-T18, FR1-FR3). */
class EventAthleteResultTest extends TestCase
{
    use RefreshDatabase;

    public function test_t10_create_event_with_valid_data(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/events', [
            'name' => '100m Final', 'event_date' => '2026-12-01', 'start_time' => '09:00',
            'end_time' => '09:30', 'venue' => 'Main Track', 'officials_required' => 4,
        ])->assertCreated()
          ->assertJsonPath('data.created_by', $user->id)
          ->assertJsonPath('data.status', 'upcoming');

        $this->assertDatabaseHas('events', ['name' => '100m Final', 'created_by' => $user->id]);
        $this->assertNotNull(\App\Models\Event::first()->uuid, 'server assigns a uuid');
    }

    public function test_t11_end_time_before_start_time_is_rejected(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/events', [
            'name' => 'Bad', 'event_date' => '2026-12-01', 'start_time' => '10:00', 'end_time' => '09:00',
        ])->assertStatus(422)->assertJsonValidationErrors('end_time');
    }

    public function test_t12_list_events_filtered_by_status(): void
    {
        $user = $this->makeUser();
        $this->makeEvent($user, ['name' => 'A', 'status' => 'upcoming']);
        $this->makeEvent($user, ['name' => 'B', 'status' => 'completed']);
        Sanctum::actingAs($user);

        $data = $this->getJson('/api/events?status=completed')->assertOk()->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('B', $data[0]['name']);
    }

    public function test_t13_dashboard_statistics_match_seeded_data(): void
    {
        $user = $this->makeUser();
        $e1 = $this->makeEvent($user);
        $e2 = $this->makeEvent($user, ['name' => 'Long Jump', 'event_date' => now()->addDay()->toDateString()]);
        $a = $this->makeAthlete($e1);
        $this->makeAthlete($e1);
        $this->makeAthlete($e2);
        Result::create(['event_id' => $e1->id, 'athlete_id' => $a->id, 'placement' => 1]);
        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('data.stats.total_events', 2)
            ->assertJsonPath('data.stats.total_athletes', 3)
            ->assertJsonPath('data.stats.results_entered', 1)
            ->assertJsonCount(1, 'data.today_events');
    }

    public function test_coordinators_cannot_see_other_coordinators_events_but_admins_can(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $admin = $this->makeUser(['role' => 'admin']);
        $event = $this->makeEvent($owner);

        Sanctum::actingAs($other);
        $this->getJson("/api/events/{$event->id}")->assertForbidden();
        $this->getJson('/api/events')->assertJsonCount(0, 'data');

        Sanctum::actingAs($admin);
        $this->getJson("/api/events/{$event->id}")->assertOk();
        $this->getJson('/api/events')->assertJsonCount(1, 'data');
    }

    public function test_starting_an_event_records_actual_start_time(): void
    {
        $user = $this->makeUser();
        $event = $this->makeEvent($user);
        Sanctum::actingAs($user);

        $this->putJson("/api/events/{$event->id}", ['status' => 'running'])->assertOk();
        $this->assertNotNull($event->fresh()->actual_start_time);
    }

    public function test_t14_duplicate_bib_number_within_event_is_rejected(): void
    {
        $user = $this->makeUser();
        $event = $this->makeEvent($user);
        $other = $this->makeEvent($user, ['name' => 'Other']);
        $this->makeAthlete($event, ['bib_number' => 7]);
        Sanctum::actingAs($user);

        $this->postJson("/api/events/{$event->id}/athletes", ['name' => 'Dup', 'bib_number' => 7])
            ->assertStatus(422)->assertJsonValidationErrors('bib_number');

        // The same bib is fine in a different event.
        $this->postJson("/api/events/{$other->id}/athletes", ['name' => 'Ok', 'bib_number' => 7])->assertCreated();
    }

    public function test_bib_number_is_generated_when_omitted(): void
    {
        $user = $this->makeUser();
        $event = $this->makeEvent($user);
        $this->makeAthlete($event, ['bib_number' => 41]);
        Sanctum::actingAs($user);

        $this->postJson("/api/events/{$event->id}/athletes", ['name' => 'Auto Bib'])
            ->assertCreated()->assertJsonPath('data.bib_number', 42);
    }

    public function test_t15_update_house_assignment_recomputes_standings(): void
    {
        $user = $this->makeUser();
        $event = $this->makeEvent($user);
        $a = $this->makeAthlete($event, ['house' => 'Red']);
        Result::create(['event_id' => $event->id, 'athlete_id' => $a->id, 'placement' => 1]);
        Sanctum::actingAs($user);

        $this->getJson("/api/events/{$event->id}/standings")->assertJsonPath('data.house_points.Red', 5);

        $this->putJson("/api/athletes/{$a->id}", ['house' => 'Blue'])->assertOk();

        $points = $this->getJson("/api/events/{$event->id}/standings")->json('data.house_points');
        $this->assertSame(['Blue' => 5], $points);
    }

    public function test_t16_athlete_with_results_cannot_be_deleted(): void
    {
        $user = $this->makeUser();
        $event = $this->makeEvent($user);
        $a = $this->makeAthlete($event);
        Result::create(['event_id' => $event->id, 'athlete_id' => $a->id, 'placement' => 2]);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/athletes/{$a->id}")->assertStatus(422);
        $this->assertDatabaseHas('athletes', ['id' => $a->id]);
        $this->assertDatabaseHas('results', ['athlete_id' => $a->id]);
    }

    public function test_t17_entering_results_updates_standings_and_medal_table(): void
    {
        $user = $this->makeUser();
        $event = $this->makeEvent($user);
        $red1 = $this->makeAthlete($event, ['house' => 'Red']);
        $blue = $this->makeAthlete($event, ['house' => 'Blue']);
        $red2 = $this->makeAthlete($event, ['house' => 'Red']);
        Sanctum::actingAs($user);

        foreach ([[$blue, 1], [$red1, 2], [$red2, 3]] as [$athlete, $place]) {
            $this->postJson("/api/events/{$event->id}/results", ['athlete_id' => $athlete->id, 'placement' => $place, 'time' => '00:11.0'])
                ->assertCreated();
        }

        $data = $this->getJson("/api/events/{$event->id}/standings")->assertOk()->json('data');
        $this->assertSame(['Blue' => 5, 'Red' => 4], $data['house_points']);
        $this->assertSame(['house' => 'Blue', 'gold' => 1, 'silver' => 0, 'bronze' => 0, 'points' => 5], $data['medal_table'][0]);
        $this->assertSame(['house' => 'Red', 'gold' => 0, 'silver' => 1, 'bronze' => 1, 'points' => 4], $data['medal_table'][1]);
        $this->assertCount(3, $data['rankings']);
    }

    public function test_t18_duplicate_result_for_same_athlete_updates_without_double_counting(): void
    {
        $user = $this->makeUser();
        $event = $this->makeEvent($user);
        $a = $this->makeAthlete($event);
        Sanctum::actingAs($user);

        $this->postJson("/api/events/{$event->id}/results", ['athlete_id' => $a->id, 'placement' => 3])->assertCreated();
        $this->postJson("/api/events/{$event->id}/results", ['athlete_id' => $a->id, 'placement' => 1])->assertOk();

        $this->assertSame(1, Result::count());
        $this->assertSame(1, Result::first()->placement);
    }

    public function test_placement_must_be_unique_within_event(): void
    {
        $user = $this->makeUser();
        $event = $this->makeEvent($user);
        $a = $this->makeAthlete($event);
        $b = $this->makeAthlete($event);
        Sanctum::actingAs($user);

        $this->postJson("/api/events/{$event->id}/results", ['athlete_id' => $a->id, 'placement' => 1])->assertCreated();
        $this->postJson("/api/events/{$event->id}/results", ['athlete_id' => $b->id, 'placement' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('placement');
    }

    public function test_logging_a_delay_computes_severity_and_revised_start(): void
    {
        $user = $this->makeUser();
        $event = $this->makeEvent($user, ['start_time' => '09:00']);
        Sanctum::actingAs($user);

        $this->postJson("/api/events/{$event->id}/delays", ['delay_minutes' => 12, 'reason' => 'False start'])
            ->assertCreated()
            ->assertJsonPath('data.severity', 'major')
            ->assertJsonPath('data.revised_start_time', '09:12');

        $this->postJson("/api/events/{$event->id}/delays", ['delay_minutes' => 20])
            ->assertJsonPath('data.revised_start_time', '09:32');
    }
}
