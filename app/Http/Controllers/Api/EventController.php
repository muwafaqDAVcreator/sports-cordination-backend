<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;

class EventController extends Controller {
    public function index(Request $request) {
        $events = Event::forUser($request->user()->id)
            ->with('athletes')
            ->orderByDesc('event_date')
            ->get();

        return response()->json(['success' => true, 'data' => $events], 200);
    }

    public function store(Request $request) {
        $validated = $request->validate([
            'name' => 'required|string|min:2',
            'event_date' => 'required|date',
            'start_time' => 'required',
            'end_time' => 'nullable',
            'venue' => 'nullable|string',
        ]);

        $event = Event::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'data' => $event], 201);
    }

    public function show(Request $request, Event $event) {
        $this->authorize('view', $event);

        return response()->json(['success' => true, 'data' => $event->load('athletes')], 200);
    }

    public function update(Request $request, Event $event) {
        $this->authorize('update', $event);

        $validated = $request->validate([
            'name' => 'sometimes|string|min:2',
            'event_date' => 'sometimes|date',
            'start_time' => 'sometimes',
            'end_time' => 'nullable',
            'venue' => 'nullable|string',
            'status' => 'sometimes|in:upcoming,running,completed',
        ]);

        $event->update($validated);

        return response()->json(['success' => true, 'data' => $event], 200);
    }

    public function destroy(Request $request, Event $event) {
        $this->authorize('delete', $event);

        $event->delete();

        return response()->json(['success' => true, 'message' => 'Event deleted'], 200);
    }
}
