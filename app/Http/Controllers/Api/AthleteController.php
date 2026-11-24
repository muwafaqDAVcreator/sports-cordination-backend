<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Athlete;
use App\Models\Event;
use App\Support\EntityRules;
use Illuminate\Http\Request;

class AthleteController extends Controller {
    public function index(Request $request, $eventId) {
        $event = Event::findOrFail($eventId);
        $this->authorize('view', $event);

        return response()->json(['success' => true, 'data' => $event->athletes()->orderBy('bib_number')->get()], 200);
    }

    public function store(Request $request, $eventId) {
        $event = Event::findOrFail($eventId);
        $this->authorize('update', $event);

        $validated = $request->validate([
            ...EntityRules::athlete($event->id),
            'uuid' => 'nullable|uuid|unique:athletes,uuid',
        ]);

        // Bib numbers are generated when not supplied, unique within the event.
        $validated['bib_number'] ??= Athlete::nextBibNumber($event->id);

        $athlete = $event->athletes()->create($validated);

        return response()->json(['success' => true, 'data' => $athlete], 201);
    }

    public function update(Request $request, $athleteId) {
        $athlete = Athlete::findOrFail($athleteId);
        $this->authorize('update', $athlete->event);

        $validated = $request->validate(EntityRules::athlete($athlete->event_id, $athlete->id, partial: true));

        $athlete->update($validated);

        return response()->json(['success' => true, 'data' => $athlete], 200);
    }

    public function destroy(Request $request, $athleteId) {
        $athlete = Athlete::findOrFail($athleteId);
        $this->authorize('update', $athlete->event);

        // Results must never be orphaned (test T16).
        if ($athlete->result()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Athlete has recorded results and cannot be deleted',
                'errors' => ['athlete' => ['Athlete has recorded results and cannot be deleted']],
            ], 422);
        }

        $athlete->delete();

        return response()->json(['success' => true, 'message' => 'Athlete deleted'], 200);
    }
}
