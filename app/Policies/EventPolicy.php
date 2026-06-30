<?php
namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy {
    public function view(User $user, Event $event): bool {
        return $user->id === $event->created_by;
    }

    public function create(User $user): bool {
        return true;
    }

    public function update(User $user, Event $event): bool {
        return $user->id === $event->created_by;
    }

    public function delete(User $user, Event $event): bool {
        return $user->id === $event->created_by;
    }
}
