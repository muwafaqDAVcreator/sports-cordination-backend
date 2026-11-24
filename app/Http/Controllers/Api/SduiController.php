<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EventAnalyticsService;
use App\Services\SduiSchemaGenerator;
use Illuminate\Http\Request;

class SduiController extends Controller {
    public function __construct(
        private SduiSchemaGenerator $generator,
        private EventAnalyticsService $analytics,
    ) {}

    /**
     * GET /api/dashboards/{role}?schema_version=N
     *
     * Coordinators may only request the coordinator layout; administrators may
     * request either. Layouts are never served for a role above the caller's.
     */
    public function show(Request $request, string $role) {
        $user = $request->user();

        if (!in_array($role, ['coordinator', 'admin'], true)) {
            return response()->json(['success' => false, 'message' => 'Unknown dashboard role'], 404);
        }
        if ($role === 'admin' && !$user->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'This dashboard is not available for your role'], 403);
        }

        $version = (int) $request->query('schema_version', SduiSchemaGenerator::CURRENT_VERSION);

        return response()->json(['success' => true, 'data' => $this->generator->generate($user, $role, $version)], 200);
    }

    /** GET /api/analytics - the raw findings behind the dashboard (transparency). */
    public function analytics(Request $request) {
        return response()->json(['success' => true, 'data' => $this->analytics->analyse($request->user())], 200);
    }
}
