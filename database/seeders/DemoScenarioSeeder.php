<?php

namespace Database\Seeders;

use App\Models\Athlete;
use App\Models\Event;
use App\Models\Result;
use App\Models\ScheduleDelay;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A live "meet day" scenario for demonstrating the analytics engine and the
 * AI-optimised SDUI dashboard. Safe to run repeatedly: events are matched on
 * name + date, so re-running refreshes the scenario instead of duplicating it.
 *
 *   php artisan db:seed --class=DemoScenarioSeeder
 *
 * Scenario (relative to the time the seeder runs):
 *  - 100m Final, Main Track: running, 12-minute delay logged  -> delay alert
 *  - 200m Heats, Main Track: starts right after the 100m Final -> knock-on risk
 *  - Shot Put, Field A: 1 of 3 officials assigned               -> resource shortfall
 *  - Long Jump, Field B: completed, all results entered          -> medal table
 *  - 4x100m Relay, Main Track: later today, fully resourced      -> no finding
 */
class DemoScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $coordinator = User::where('email', 'muwa@example.com')->first()
            ?? User::where('role', 'coordinator')->first();
        if (!$coordinator) {
            $this->command?->warn('No coordinator user found; run UserSeeder first.');
            return;
        }

        $now = now();
        $t = fn (int $minutes) => $now->copy()->addMinutes($minutes)->format('H:i');

        $events = [
            ['name' => '100m Final', 'discipline' => 'Track', 'start_time' => $t(-20), 'end_time' => $t(10), 'venue' => 'Main Track', 'status' => 'running', 'officials_required' => 4, 'officials_assigned' => 4, 'actual_start_time' => $t(-8)],
            ['name' => '200m Heats', 'discipline' => 'Track', 'start_time' => $t(15), 'end_time' => $t(45), 'venue' => 'Main Track', 'status' => 'upcoming', 'officials_required' => 4, 'officials_assigned' => 4],
            ['name' => 'Shot Put', 'discipline' => 'Field', 'start_time' => $t(30), 'end_time' => $t(90), 'venue' => 'Field A', 'status' => 'upcoming', 'officials_required' => 3, 'officials_assigned' => 1],
            ['name' => 'Long Jump', 'discipline' => 'Field', 'start_time' => $t(-150), 'end_time' => $t(-90), 'venue' => 'Field B', 'status' => 'completed', 'officials_required' => 2, 'officials_assigned' => 2],
            ['name' => '4x100m Relay', 'discipline' => 'Track', 'start_time' => $t(180), 'end_time' => $t(210), 'venue' => 'Main Track', 'status' => 'upcoming', 'officials_required' => 6, 'officials_assigned' => 6],
        ];

        $houses = ['Red', 'Blue', 'Green', 'Yellow'];
        $names = ['Kamal Perera', 'Nimal Silva', 'Suresh Fernando', 'Ruwan Jayasinghe', 'Chamara Wickrama', 'Lasith Bandara', 'Pradeep Kumara', 'Sanjeewa Mendis'];

        foreach ($events as $data) {
            $event = Event::updateOrCreate(
                ['name' => $data['name'], 'event_date' => $now->toDateString(), 'created_by' => $coordinator->id],
                $data + ['actual_start_time' => null],
            );

            $event->delays()->delete();
            $event->results()->delete();
            $event->athletes()->delete();

            foreach ($names as $i => $name) {
                Athlete::create([
                    'event_id' => $event->id,
                    'name' => $name,
                    'house' => $houses[$i % 4],
                    'age' => 18 + ($i % 5),
                    'bib_number' => 100 + $i + 1,
                ]);
            }
        }

        $final = Event::where('name', '100m Final')->whereDate('event_date', $now)->where('created_by', $coordinator->id)->first();
        ScheduleDelay::create([
            'event_id' => $final->id,
            'delay_minutes' => 12,
            'reason' => 'False start recall',
            'severity' => ScheduleDelay::severityFor(12),
            'revised_start_time' => $now->copy()->addMinutes(-8)->format('H:i'),
            'reported_by' => $coordinator->id,
        ]);

        $longJump = Event::where('name', 'Long Jump')->whereDate('event_date', $now)->where('created_by', $coordinator->id)->first();
        foreach ($longJump->athletes()->orderBy('bib_number')->get()->shuffle()->values() as $i => $athlete) {
            Result::create([
                'event_id' => $longJump->id,
                'athlete_id' => $athlete->id,
                'placement' => $i + 1,
                'time' => sprintf('%.2fm', 7.4 - $i * 0.18),
            ]);
        }
    }
}
