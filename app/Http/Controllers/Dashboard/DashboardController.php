<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\UserDetection;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $client = auth()->user();

        // Today's stats
        $todayQuery = UserDetection::forClient($client->id)
            ->where('detected_at', '>=', now()->startOfDay());

        $stats = [
            'total_calls' => (clone $todayQuery)->count(),
            'unique_users' => (clone $todayQuery)->distinct('fingerprint_id')->count('fingerprint_id'),
            'avg_confidence' => (int) (clone $todayQuery)->avg('confidence') ?: 0,
            'vpn_rate' => $this->calculateVpnRate(clone $todayQuery),
        ];

        // Last 7 days chart data
        $chartData = UserDetection::forClient($client->id)
            ->where('detected_at', '>=', now()->subDays(7))
            ->select(
                DB::raw("DATE(detected_at) as date"),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();

        // Top 5 cities (last 7 days)
        $topCities = UserDetection::forClient($client->id)
            ->where('detected_at', '>=', now()->subDays(7))
            ->whereNotNull('detected_city')
            ->select('detected_city as city', DB::raw('COUNT(*) as count'))
            ->groupBy('detected_city')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->toArray();

        // Confidence distribution (last 7 days)
        $confQuery = UserDetection::forClient($client->id)
            ->where('detected_at', '>=', now()->subDays(7));

        $confidenceDistribution = [
            'high' => (clone $confQuery)->where('confidence', '>=', 80)->count(),
            'medium' => (clone $confQuery)->whereBetween('confidence', [60, 79])->count(),
            'low' => (clone $confQuery)->where('confidence', '<', 60)->count(),
        ];

        // Recent detections
        $recentDetections = UserDetection::forClient($client->id)
            ->orderByDesc('detected_at')
            ->limit(20)
            ->get();

        return view('dashboard.home', compact(
            'stats',
            'chartData',
            'topCities',
            'confidenceDistribution',
            'recentDetections'
        ));
    }

    private function calculateVpnRate($query): float
    {
        $total = (clone $query)->count();
        if ($total === 0) {
            return 0;
        }

        $vpnCount = $query->where('is_vpn', true)->count();

        return round(($vpnCount / $total) * 100, 1);
    }
}
