<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class ApiRateLimiter
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->attributes->get('client');

        if (!$client) {
            return $next($request);
        }

        $limit = $client->getRateLimit();
        $key = "ratelimit:{$client->id}:" . now()->format('Y-m-d-H-i');

        try {
            $current = Redis::incr($key);

            if ($current === 1) {
                Redis::expire($key, 60);
            }

            if ($current > $limit) {
                $retryAfter = 60 - now()->second;

                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMIT_EXCEEDED',
                        'message' => "Rate limit exceeded. Max {$limit} requests per minute.",
                        'retry_after' => $retryAfter,
                    ],
                ], 429)->withHeaders([
                    'Retry-After' => $retryAfter,
                    'X-RateLimit-Limit' => $limit,
                    'X-RateLimit-Remaining' => 0,
                    'X-RateLimit-Reset' => now()->addSeconds($retryAfter)->timestamp,
                ]);
            }

            $response = $next($request);

            return $response->withHeaders([
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => max(0, $limit - $current),
                'X-RateLimit-Reset' => now()->addSeconds(60 - now()->second)->timestamp,
            ]);
        } catch (\Throwable $e) {
            // If Redis is down, allow the request through (fail-open)
            return $next($request);
        }
    }
}
