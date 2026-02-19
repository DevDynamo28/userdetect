<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key');

        if (!$apiKey) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'MISSING_API_KEY',
                    'message' => 'API key is required. Pass it via X-API-Key header.',
                ],
            ], 401);
        }

        $cacheTtl = config('detection.cache.api_key_ttl', 300);
        $cacheKey = "client_key:{$apiKey}";

        try {
            $client = Cache::remember($cacheKey, $cacheTtl, function () use ($apiKey) {
                return Client::where('api_key', $apiKey)
                    ->where('status', 'active')
                    ->first();
            });
        } catch (\Throwable $e) {
            // If cache or DB fails, try a direct DB lookup before failing the request.
            Log::warning('API key cache lookup failed', [
                'error' => $e->getMessage(),
            ]);

            try {
                $client = Client::where('api_key', $apiKey)
                    ->where('status', 'active')
                    ->first();
            } catch (\Throwable $dbException) {
                Log::error('API key lookup failed due to database error', [
                    'error' => $dbException->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'SERVICE_UNAVAILABLE',
                        'message' => 'Authentication service is temporarily unavailable.',
                    ],
                ], 503);
            }
        }

        if (!$client) {
            // Clear cache for invalid keys to prevent stale data
            try {
                Cache::forget($cacheKey);
            } catch (\Throwable $e) {
                Log::warning('Failed to clear API key cache', [
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_API_KEY',
                    'message' => 'API key is invalid or inactive.',
                ],
            ], 401);
        }

        // Debounced last_api_call update (only if > 1 minute since last)
        if (!$client->last_api_call || $client->last_api_call->diffInMinutes(now()) >= 1) {
            try {
                $client->update(['last_api_call' => now()]);
            } catch (\Throwable $e) {
                // Do not fail request flow for analytics metadata update.
                Log::warning('Failed to update client last_api_call', [
                    'client_id' => $client->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Attach client to request for downstream use
        $request->merge(['client' => $client]);
        $request->attributes->set('client', $client);

        return $next($request);
    }
}
