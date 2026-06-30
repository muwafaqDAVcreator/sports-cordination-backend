<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Athlete;
use Illuminate\Http\Request;

class AthleteController extends Controller {
    public function index(Request $request, $eventId) {
        $event = Event::findOrFail($eventId);
        $this->authorize('view', $event);

        return response()->json(['success' => true, 'data' => $event->athletes], 200);
    }

    public function store(Request $request, $eventId) {
        $event = Event::findOrFail($eventId);
        $this->authorize('update', $event);

        $validated = $request->validate([
            'name' => 'required|string|min:2',
            'bib_number' => 'nullable|integer',
            'age' => 'nullable|integer|min:5|max:100',
            'house' => 'nullable|string',
        ]);

        $athlete = $event->athletes()->create($validated);

        return response()->json(['success' => true, 'data' => $athlete], 201);
    }

    public function update(Request $request, $athleteId) {
        $athlete = Athlete::findOrFail($athleteId);
        $this->authorize('update', $athlete->event);

        $validated = $request->validate([
            'name' => 'sometimes|string|min:2',
            'bib_number' => 'nullable|integer',
            'age' => 'nullable|integer|min:5|max:100',
            'house' => 'nullable|string',
        ]);

        $athlete->update($validated);

        return response()->json(['success' => true, 'data' => $athlete], 200);
    }

    public function destroy(Request $request, $athleteId) {
        $athlete = Athlete::findOrFail($athleteId);
        $this->authorize('update', $athlete->event);

        $athlete->delete();

        return response()->json(['success' => true, 'message' => 'Athlete deleted'], 200);
    }
}
