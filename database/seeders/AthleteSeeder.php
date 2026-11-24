<?php

namespace Database\Seeders;

use App\Models\Athlete;
use App\Models\Event;
use Illuminate\Database\Seeder;

class AthleteSeeder extends Seeder
{
    public function run(): void
    {
        $houses = ['Red', 'Blue', 'Green', 'Yellow'];

        $athleteNames = [
            'Kamal Perera', 'Nimal Silva', 'Suresh Fernando', 'Ruwan Jayasinghe',
            'Chamara Wickrama', 'Lasith Bandara', 'Pradeep Kumara', 'Sanjeewa Mendis',
            'Dilshan Rajapaksa', 'Thilanka Gunaratne', 'Asanka Dissanayake', 'Mahesh Senanayake',
            'Roshan Pathirana', 'Saman Ranasinghe', 'Hasitha Kodithuwakku', 'Buddhika Madushan',
        ];

        $events = Event::all();

        foreach ($events as $event) {
            foreach ($athleteNames as $index => $name) {
                Athlete::create([
                    'name'     => $name,
                    'house'    => $houses[$index % 4],
                    'age'      => rand(14, 22),
                    'event_id' => $event->id,
                ]);
            }
        }
    }
}
