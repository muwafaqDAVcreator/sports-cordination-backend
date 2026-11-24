<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Athlete;
use App\Models\Event;
use App\Models\Result;
use App\Models\ScheduleDelay;
use App\Models\SyncConflict;
use App\Services\SyncSerializer;
use App\Services\SyncService;
use Illuminate\Http\Request;

class SyncController extends Controller {
    public function __construct(private SyncService $sync) {}

    public function status(Request $request) {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'last_sync_at' => $user->last_sync_at,
                'server_time' => now()->toIso8601ZuluString('millisecond'),
                'conflicts_24h' => SyncConflict::where('user_id', $user->id)->where('created_at', '>=', now()->subDay())->count(),
            ],
        ], 200);
    }

    /** Legacy endpoint kept for older clients: records a sync heartbeat. */
    public function sync(Request $request) {
        $user = $request->user();
        $user->update(['last_sync_at' => now()]);

        return response()->json(['success' => true, 'data' => ['last_sync_at' => $user->last_sync_at]], 200);
    }

    /**
     * POST /api/sync/upload
     * Body: { mutations: [ {id, entity, op, uuid, data, client_updated_at, base_updated_at} ] }
     */
    public function upload(Request $request) {
        $request->validate([
            'mutations' => 'present|array|max:100',
            'mutations.*' => 'array',
        ]);

        $results = $this->sync->process($request->user(), $request->input('mutations', []));

        return response()->json([
            'success' => true,
            'data' => [
                'results' => $results,
                'server_time' => now()->toIso8601ZuluString('millisecond'),
                'summary' => collect($results)->countBy('status'),
            ],
        ], 200);
    }

    /**
     * GET /api/sync/download
     * Full snapshot of every record the user can see, uuid-addressed, for the
     * client to merge into its local SQLite mirror.
     */
    public function download(Request $request) {
        $eventIds = Event::visibleTo($request->user())->pluck('id');

        return response()->json([
            'success' => true,
            'data' => [
                'server_time' => now()->toIso8601ZuluString('millisecond'),
                'events' => Event::whereIn('id', $eventIds)->get()->map(fn ($e) => SyncSerializer::event($e)),
                'athletes' => Athlete::with('event')->whereIn('event_id', $eventIds)->get()->map(fn ($a) => SyncSerializer::athlete($a)),
                'results' => Result::with(['event', 'athlete'])->whereIn('event_id', $eventIds)->get()->map(fn ($r) => SyncSerializer::result($r)),
                'delays' => ScheduleDelay::with('event')->whereIn('event_id', $eventIds)->get()->map(fn ($d) => SyncSerializer::delay($d)),
            ],
        ], 200);
    }

    /** GET /api/sync/conflicts - the conflict audit log (own entries; admins see all). */
    public function conflicts(Request $request) {
        $user = $request->user();
        $query = SyncConflict::latest()->limit(50);
        if (!$user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        return response()->json(['success' => true, 'data' => $query->get()], 200);
    }
}
