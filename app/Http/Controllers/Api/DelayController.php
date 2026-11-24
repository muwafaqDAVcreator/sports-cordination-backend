<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\ScheduleDelay;
use App\Support\EntityRules;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DelayController extends Controller {
    public function index(Request $request, Event $event) {
        $this->authorize('view', $event);

        return response()->json(['success' => true, 'data' => $event->delays], 200);
    }

    public function store(Request $request, Event $event) {
        $this->authorize('update', $event);

        $validated = $request->validate([
            ...EntityRules::delay(),
            'uuid' => 'nullable|uuid|unique:schedule_delays,uuid',
        ]);

        $total = $event->delays()->sum('delay_minutes') + $validated['delay_minutes'];

        $delay = $event->delays()->create([
            ...$validated,
            'severity' => ScheduleDelay::severityFor($validated['delay_minutes']),
            'revised_start_time' => Carbon::parse($event->start_time)->addMinutes($total)->format('H:i'),
            'reported_by' => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'data' => $delay], 201);
    }
}
