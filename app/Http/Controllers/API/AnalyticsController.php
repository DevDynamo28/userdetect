<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnalyticsSummaryResource;
use App\Models\UserDetection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    public function summary(Request $request)
    {
        $client = $request->attributes->get('client');
        $period = $request->query('period', 'last_7_days');

        $query = UserDetection::forClient($client->id)->forPeriod($period);

        // Usage stats
        $totalRequests = (clone $query)->count();
        $uniqueUsers = (clone $query)->distinct('fingerprint_id')->count('fingerprint_id');
        $avgConfidence = (int) (clone $query)->avg('confidence');

        // Top cities
        $topCities = (clone $query)
            ->whereNotNull('detected_city')
            ->select('detected_city as city', DB::raw('COUNT(*) as count'))
            ->groupBy('detected_city')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(function ($row) use ($totalRequests) {
                return [
                    'city' => $row->city,
                    'count' => $row->count,
                    'percentage' => $totalRequests > 0
                        ? round(($row->count / $totalRequests) * 100, 1)
                        : 0,
                ];
            })
            ->toArray();

        // Detection methods breakdown
        $methods = (clone $query)
            ->select('detection_method', DB::raw('COUNT(*) as count'))
            ->groupBy('detection_method')
            ->pluck('count', 'detection_method')
            ->toArray();

        // VPN stats
        $vpnCount = (clone $query)->where('is_vpn', true)->count();

        // Confidence distribution
        $highConf = (clone $query)->where('confidence', '>=', 80)->count();
        $medConf = (clone $query)->whereBetween('confidence', [60, 79])->count();
        $lowConf = (clone $query)->where('confidence', '<', 60)->count();

        return new AnalyticsSummaryResource([
            'period' => $period,
            'total_requests' => $totalRequests,
            'unique_users' => $uniqueUsers,
            'average_confidence' => $avgConfidence,
            'top_cities' => $topCities,
            'detection_methods' => $methods,
            'vpn_count' => $vpnCount,
            'confidence_distribution' => [
                'high (80-100)' => $highConf,
                'medium (60-79)' => $medConf,
                'low (0-59)' => $lowConf,
            ],
        ]);
    }
}
