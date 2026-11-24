<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Athlete;
use App\Models\Event;
use App\Models\Result;
use Illuminate\Http\Request;

class DashboardController extends Controller {
    public function index(Request $request) {
        $user = $request->user();
        $eventIds = Event::visibleTo($user)->pluck('id');

        $todayEvents = Event::whereIn('id', $eventIds)->today()->with(['athletes', 'delays'])->orderBy('start_time')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'today_events' => $todayEvents,
                'stats' => [
                    'total_events' => $eventIds->count(),
                    'total_athletes' => Athlete::whereIn('event_id', $eventIds)->count(),
                    'results_entered' => Result::whereIn('event_id', $eventIds)->count(),
                    'pending_changes' => 0, // the device reports its own queue length
                ],
                'last_sync_time' => $user->last_sync_at,
            ],
        ], 200);
    }
}
