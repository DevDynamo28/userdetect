<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        $client = Cache::remember("client_key:{$apiKey}", $cacheTtl, function () use ($apiKey) {
            return Client::where('api_key', $apiKey)
                ->where('status', 'active')
                ->first();
        });

        if (!$client) {
            // Clear cache for invalid keys to prevent stale data
            Cache::forget("client_key:{$apiKey}");

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
            $client->update(['last_api_call' => now()]);
        }

        // Attach client to request for downstream use
        $request->merge(['client' => $client]);
        $request->attributes->set('client', $client);

        return $next($request);
    }
}
