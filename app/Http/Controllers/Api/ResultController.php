<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Athlete;
use App\Models\Event;
use App\Models\Result;
use App\Services\StandingsService;
use App\Support\EntityRules;
use Illuminate\Http\Request;

class ResultController extends Controller {
    public function __construct(private StandingsService $standings) {}

    public function index(Request $request, $eventId) {
        $event = Event::findOrFail($eventId);
        $this->authorize('view', $event);

        $results = $event->results()->with('athlete')->orderBy('placement')->get();

        return response()->json(['success' => true, 'data' => $results], 200);
    }

    public function store(Request $request, $eventId) {
        $event = Event::findOrFail($eventId);
        $this->authorize('update', $event);

        $request->validate(['athlete_id' => 'required|integer']);
        $athlete = Athlete::where('event_id', $event->id)->find($request->athlete_id);
        if (!$athlete) {
            return response()->json(['success' => false, 'message' => 'Athlete not found in this event', 'errors' => ['athlete_id' => ['Athlete not found in this event']]], 422);
        }

        // One result per athlete per event: re-submitting updates in place
        // rather than double counting (test T18).
        $existing = Result::where('event_id', $event->id)->where('athlete_id', $athlete->id)->first();
        $validated = $request->validate(EntityRules::result($event->id, $existing?->id));

        $result = Result::updateOrCreate(
            ['event_id' => $event->id, 'athlete_id' => $athlete->id],
            [
                'placement' => $validated['placement'] ?? null,
                'time' => $validated['time'] ?? null,
                'wind_speed' => $validated['wind_speed'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return response()->json(['success' => true, 'data' => $result->load('athlete')], $existing ? 200 : 201);
    }

    public function standings(Request $request, $eventId) {
        $event = Event::findOrFail($eventId);
        $this->authorize('view', $event);

        return response()->json(['success' => true, 'data' => $this->standings->forEvent($event)], 200);
    }

    public function overall(Request $request) {
        $eventIds = Event::visibleTo($request->user())->pluck('id');

        return response()->json(['success' => true, 'data' => ['medal_table' => $this->standings->overall($eventIds)]], 200);
    }

    public function update(Request $request, $resultId) {
        $result = Result::findOrFail($resultId);
        $this->authorize('update', $result->event);

        $validated = $request->validate(EntityRules::result($result->event_id, $result->id));

        $result->update($validated);

        return response()->json(['success' => true, 'data' => $result->load('athlete')], 200);
    }

    public function destroy(Request $request, $resultId) {
        $result = Result::findOrFail($resultId);
        $this->authorize('update', $result->event);

        $result->delete();

        return response()->json(['success' => true, 'message' => 'Result deleted'], 200);
    }
}
