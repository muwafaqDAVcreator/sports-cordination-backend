<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SyncController extends Controller {
    public function status(Request $request) {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'last_sync_at' => $user->last_sync_at,
                'server_time' => now(),
            ],
        ], 200);
    }

    public function sync(Request $request) {
        $user = $request->user();
        $user->update(['last_sync_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => ['last_sync_at' => $user->last_sync_at],
        ], 200);
    }
}
