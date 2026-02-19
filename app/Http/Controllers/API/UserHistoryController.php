<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserHistoryResource;
use App\Models\UserFingerprint;
use Illuminate\Http\Request;

class UserHistoryController extends Controller
{
    public function show(Request $request, string $fingerprintId)
    {
        $client = $request->attributes->get('client');

        $fingerprint = UserFingerprint::where('client_id', $client->id)
            ->where('fingerprint_id', $fingerprintId)
            ->first();

        if (!$fingerprint) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'USER_NOT_FOUND',
                    'message' => 'No data found for this fingerprint ID.',
                ],
            ], 404);
        }

        // Build location history from city_visit_counts
        $cityCounts = $fingerprint->city_visit_counts ?? [];
        $totalVisits = array_sum($cityCounts);

        $locationHistory = collect($cityCounts)
            ->map(fn ($count, $city) => [
                'city' => $city,
                'count' => $count,
                'percentage' => $totalVisits > 0 ? round(($count / $totalVisits) * 100, 1) : 0,
            ])
            ->sortByDesc('count')
            ->values()
            ->toArray();

        return new UserHistoryResource([
            'fingerprint' => $fingerprint,
            'location_history' => $locationHistory,
        ]);
    }
}
