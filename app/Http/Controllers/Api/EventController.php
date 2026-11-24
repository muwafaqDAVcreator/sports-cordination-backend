<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\EntityRules;
use Illuminate\Http\Request;

class EventController extends Controller {
    public function index(Request $request) {
        $request->validate(['status' => 'nullable|in:upcoming,running,completed']);

        $query = Event::visibleTo($request->user())
            ->with(['athletes', 'delays'])
            ->withCount('results')
            ->orderByDesc('event_date')
            ->orderBy('start_time');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json(['success' => true, 'data' => $query->get()], 200);
    }

    public function store(Request $request) {
        $validated = $request->validate([
            ...EntityRules::event(),
            'uuid' => 'nullable|uuid|unique:events,uuid',
        ]);

        $event = Event::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'data' => $event->fresh()], 201);
    }

    public function show(Request $request, Event $event) {
        $this->authorize('view', $event);

        return response()->json(['success' => true, 'data' => $event->load(['athletes', 'delays'])], 200);
    }

    public function update(Request $request, Event $event) {
        $this->authorize('update', $event);

        $input = $request->all();
        if (!$request->has('start_time') && $request->has('end_time')) {
            $input['start_time'] = substr((string) $event->start_time, 0, 5);
        }
        $validated = validator($input, EntityRules::event(partial: true))->validate();

        // Starting an event records its actual start time, which the
        // analytics engine compares with the scheduled time.
        if (($validated['status'] ?? null) === 'running' && !$event->actual_start_time && !isset($validated['actual_start_time'])) {
            $validated['actual_start_time'] = now()->format('H:i');
        }

        $event->update($validated);

        return response()->json(['success' => true, 'data' => $event->fresh()], 200);
    }

    public function destroy(Request $request, Event $event) {
        $this->authorize('delete', $event);

        $event->delete();

        return response()->json(['success' => true, 'message' => 'Event deleted'], 200);
    }
}
