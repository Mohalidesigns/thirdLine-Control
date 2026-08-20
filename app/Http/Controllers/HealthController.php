<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Operational health for load balancers and the on-call engineer (16.3).
 * /up (framework) answers "is the process alive"; this answers "is the
 * platform serving" — database, cache, queue depth and failure counts.
 * No tenant data and no secrets: safe for an internal monitor, still
 * placed behind auth in production ingress rules (docs/deployment).
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1') !== []),
            'cache' => $this->check(function () {
                Cache::put('health-probe', 1, 5);

                return Cache::get('health-probe') === 1;
            }),
        ];

        $queue = $this->queueDepth();

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'queue' => $queue,
            'time' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    private function check(callable $probe): array
    {
        try {
            $start = microtime(true);
            $ok = (bool) $probe();

            return ['ok' => $ok, 'ms' => round((microtime(true) - $start) * 1000, 1)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function queueDepth(): array
    {
        try {
            return [
                'pending' => config('queue.default') === 'database'
                    ? DB::table('jobs')->count()
                    : null,
                'failed' => DB::table('failed_jobs')->count(),
            ];
        } catch (\Throwable) {
            return ['pending' => null, 'failed' => null];
        }
    }
}
