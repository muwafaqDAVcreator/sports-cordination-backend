<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;

class DashboardController extends Controller {
    public function index(Request $request) {
        $user = $request->user();

        $todayEvents = Event::forUser($user->id)
            ->today()
            ->with('athletes')
            ->get();

        $totalEvents = Event::forUser($user->id)->count();

        $totalAthletes = \DB::table('athletes')
            ->whereIn('event_id', Event::forUser($user->id)->pluck('id'))
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'today_events' => $todayEvents,
                'stats' => [
                    'total_events' => $totalEvents,
                    'total_athletes' => $totalAthletes,
                    'results_entered' => 0,
                    'pending_changes' => 0,
                ],
                'last_sync_time' => $user->last_sync_at,
            ],
        ], 200);
    }
}
