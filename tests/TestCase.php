<?php

namespace Tests;

use App\Models\Athlete;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected function makeUser(array $attrs = []): User
    {
        static $n = 0;
        $n++;

        return User::create(array_merge([
            'name' => "User $n",
            'email' => "user$n@example.test",
            'phone' => '0770000000',
            'password' => Hash::make('password123'),
            'role' => 'coordinator',
        ], $attrs));
    }

    protected function makeEvent(User $owner, array $attrs = []): Event
    {
        return Event::create(array_merge([
            'name' => '100m Sprint',
            'event_date' => now()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'venue' => 'Main Track',
            'status' => 'upcoming',
            'created_by' => $owner->id,
        ], $attrs));
    }

    protected function makeAthlete(Event $event, array $attrs = []): Athlete
    {
        static $bib = 0;
        $bib++;

        return Athlete::create(array_merge([
            'event_id' => $event->id,
            'name' => "Athlete $bib",
            'bib_number' => $bib,
            'house' => 'Red',
        ], $attrs));
    }

    protected function mutation(string $entity, string $op, string $uuid, array $data = [], array $extra = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'entity' => $entity,
            'op' => $op,
            'uuid' => $uuid,
            'data' => $data,
            'client_updated_at' => now()->toIso8601ZuluString('millisecond'),
        ], $extra);
    }
}
