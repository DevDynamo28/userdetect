<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            // Atomic incr + expire using Lua script to prevent race condition
            $luaScript = <<<'LUA'
                local current = redis.call('incr', KEYS[1])
                if current == 1 then
                    redis.call('expire', KEYS[1], 60)
                end
                return current
            LUA;

            $current = Redis::eval($luaScript, 1, $key);

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
            // If Redis is down, log and allow the request through with a warning header
            Log::warning('Rate limiter unavailable, failing open', ['error' => $e->getMessage()]);

            $response = $next($request);

            return $response->withHeaders([
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => 'unknown',
            ]);
        }
    }
}
