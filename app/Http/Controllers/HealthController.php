<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Observability\HealthChecks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET /health/deep: 200 when every dependency answers, 503 with the failing
 * check named when one does not. For the monitor, not the load balancer
 * (that one is /up): a replica should not be taken out of rotation because
 * Horizon, which runs elsewhere, is down.
 */
class HealthController extends Controller
{
    public function deep(Request $request, HealthChecks $checks): JsonResponse
    {
        // 404, not 401: the endpoint does not exist for anyone else.
        abort_unless($this->allowed($request), 404);

        $report = $checks->run();

        return response()->json($report, $report['ok'] ? 200 : 503, ['Cache-Control' => 'no-store']);
    }

    private function allowed(Request $request): bool
    {
        $token = (string) config('observability.health.token');
        $given = (string) $request->bearerToken();
        if ($token !== '' && $given !== '' && hash_equals($token, $given)) {
            return true;
        }

        $admin = Auth::guard('admin')->user();

        return $admin instanceof Admin && $admin->isOperator();
    }
}
