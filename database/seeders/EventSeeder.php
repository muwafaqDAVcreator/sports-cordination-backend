<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;

class EventSeeder extends Seeder
{
    public function run(): void
    {
        $coordinator = User::where('email', 'muwa@example.com')->first();

        $events = [
            [
                'name'       => '100m Sprint',
                'event_date' => now()->toDateString(),
                'start_time' => '09:00',
                'end_time'   => '10:00',
                'venue'      => 'Main Track',
                'status'     => 'upcoming',
            ],
            [
                'name'       => '200m Sprint',
                'event_date' => now()->toDateString(),
                'start_time' => '10:30',
                'end_time'   => '11:30',
                'venue'      => 'Main Track',
                'status'     => 'upcoming',
            ],
            [
                'name'       => 'Long Jump',
                'event_date' => now()->addDay()->toDateString(),
                'start_time' => '09:00',
                'end_time'   => '11:00',
                'venue'      => 'Field A',
                'status'     => 'upcoming',
            ],
            [
                'name'       => '4x100m Relay',
                'event_date' => now()->addDays(2)->toDateString(),
                'start_time' => '14:00',
                'end_time'   => '15:00',
                'venue'      => 'Main Track',
                'status'     => 'upcoming',
            ],
            [
                'name'       => 'High Jump',
                'event_date' => now()->subDay()->toDateString(),
                'start_time' => '08:00',
                'end_time'   => '10:00',
                'venue'      => 'Field B',
                'status'     => 'completed',
            ],
        ];

        foreach ($events as $data) {
            Event::create([
                ...$data,
                'created_by' => $coordinator->id,
            ]);
        }
    }
}
