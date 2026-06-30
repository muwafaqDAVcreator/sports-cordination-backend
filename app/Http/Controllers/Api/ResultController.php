<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Result;
use Illuminate\Http\Request;

class ResultController extends Controller {
    public function index(Request $request, $eventId) {
        $event = Event::findOrFail($eventId);
        $this->authorize('view', $event);

        $results = $event->results()->with('athlete')->orderBy('placement')->get();

        return response()->json(['success' => true, 'data' => $results], 200);
    }

    public function store(Request $request, $eventId) {
        $event = Event::findOrFail($eventId);
        $this->authorize('update', $event);

        $validated = $request->validate([
            'athlete_id' => 'required|exists:athletes,id',
            'placement' => 'nullable|integer|min:1|max:8|unique:results,placement,NULL,id,event_id,' . $eventId,
            'time' => 'nullable|string',
            'wind_speed' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

        $result = Result::updateOrCreate(
            ['event_id' => $eventId, 'athlete_id' => $validated['athlete_id']],
            [
                'placement' => $validated['placement'] ?? null,
                'time' => $validated['time'] ?? null,
                'wind_speed' => $validated['wind_speed'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return response()->json(['success' => true, 'data' => $result->load('athlete')], 201);
    }

    public function standings(Request $request, $eventId) {
        $event = Event::findOrFail($eventId);
        $this->authorize('view', $event);

        $results = $event->results()
            ->with('athlete')
            ->whereNotNull('placement')
            ->orderBy('placement')
            ->get();

        $points = [1 => 5, 2 => 3, 3 => 1];
        $housePoints = [];

        foreach ($results as $result) {
            $house = $result->athlete->house ?? 'Unassigned';
            $earned = $points[$result->placement] ?? 0;
            $housePoints[$house] = ($housePoints[$house] ?? 0) + $earned;
        }

        arsort($housePoints);

        return response()->json([
            'success' => true,
            'data' => [
                'rankings' => $results,
                'house_points' => $housePoints,
            ],
        ], 200);
    }

    public function update(Request $request, $resultId) {
        $result = Result::findOrFail($resultId);
        $this->authorize('update', $result->event);

        $validated = $request->validate([
            'placement' => 'nullable|integer|min:1|max:8|unique:results,placement,' . $resultId . ',id,event_id,' . $result->event_id,
            'time' => 'nullable|string',
            'wind_speed' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

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
