<?php

namespace Database\Seeders;

use App\Models\Athlete;
use App\Models\Event;
use App\Models\Result;
use Illuminate\Database\Seeder;

class ResultSeeder extends Seeder
{
    public function run(): void
    {
        // Only seed results for completed events
        $completedEvents = Event::where('status', 'completed')->get();

        foreach ($completedEvents as $event) {
            $athletes = Athlete::where('event_id', $event->id)->get()->shuffle();

            foreach ($athletes->take(8) as $placement => $athlete) {
                Result::create([
                    'event_id'   => $event->id,
                    'athlete_id' => $athlete->id,
                    'placement'  => $placement + 1,
                    'notes'      => null,
                ]);
            }
        }
    }
}
