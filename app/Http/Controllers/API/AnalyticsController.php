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
        $lowConfidenceThreshold = 55;
        $cityHitCount = (clone $query)->whereNotNull('detected_city')->count();
        $lowConfidenceCount = (clone $query)->where('confidence', '<', $lowConfidenceThreshold)->count();
        $disagreementCount = (clone $query)
            ->where(function ($q) {
                $q->where('city_disagreement_count', '>', 0)
                    ->orWhere('state_disagreement_count', '>', 0);
            })
            ->count();
        $verifiedTotal = (clone $query)->whereNotNull('verified_city')->count();
        $verifiedMatches = (clone $query)
            ->whereNotNull('verified_city')
            ->where('is_location_verified', true)
            ->count();
        $verifiedMismatches = max(0, $verifiedTotal - $verifiedMatches);

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

        $cityHitRateByMethod = (clone $query)
            ->select(
                'detection_method',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN detected_city IS NOT NULL THEN 1 ELSE 0 END) as city_hits')
            )
            ->groupBy('detection_method')
            ->get()
            ->map(function ($row) {
                $total = (int) $row->total;
                $cityHits = (int) $row->city_hits;

                return [
                    'method' => $row->detection_method,
                    'total' => $total,
                    'city_hits' => $cityHits,
                    'city_hit_rate' => $total > 0 ? round(($cityHits / $total) * 100, 1) : 0.0,
                ];
            })
            ->toArray();

        $disagreementRateByMethod = (clone $query)
            ->select(
                'detection_method',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN COALESCE(city_disagreement_count, 0) > 0 OR COALESCE(state_disagreement_count, 0) > 0 THEN 1 ELSE 0 END) as disagreements')
            )
            ->groupBy('detection_method')
            ->get()
            ->map(function ($row) {
                $total = (int) $row->total;
                $disagreements = (int) $row->disagreements;

                return [
                    'method' => $row->detection_method,
                    'total' => $total,
                    'disagreements' => $disagreements,
                    'disagreement_rate' => $total > 0 ? round(($disagreements / $total) * 100, 1) : 0.0,
                ];
            })
            ->toArray();

        $verifiedAccuracyByMethod = (clone $query)
            ->whereNotNull('verified_city')
            ->select(
                'detection_method',
                DB::raw('COUNT(*) as verified_total'),
                DB::raw('SUM(CASE WHEN is_location_verified THEN 1 ELSE 0 END) as verified_matches')
            )
            ->groupBy('detection_method')
            ->get()
            ->map(function ($row) {
                $verifiedTotal = (int) $row->verified_total;
                $verifiedMatches = (int) $row->verified_matches;

                return [
                    'method' => $row->detection_method,
                    'verified_total' => $verifiedTotal,
                    'verified_matches' => $verifiedMatches,
                    'accuracy_rate' => $verifiedTotal > 0 ? round(($verifiedMatches / $verifiedTotal) * 100, 1) : 0.0,
                ];
            })
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
            'quality_metrics' => [
                'city_hit_rate' => $totalRequests > 0 ? round(($cityHitCount / $totalRequests) * 100, 1) : 0.0,
                'city_hit_rate_by_method' => $cityHitRateByMethod,
                'low_confidence_count' => $lowConfidenceCount,
                'low_confidence_rate' => $totalRequests > 0 ? round(($lowConfidenceCount / $totalRequests) * 100, 1) : 0.0,
                'disagreement_count' => $disagreementCount,
                'disagreement_rate' => $totalRequests > 0 ? round(($disagreementCount / $totalRequests) * 100, 1) : 0.0,
                'disagreement_rate_by_method' => $disagreementRateByMethod,
                'verified_label_total' => $verifiedTotal,
                'verified_label_matches' => $verifiedMatches,
                'verified_label_mismatches' => $verifiedMismatches,
                'verified_label_coverage_rate' => $totalRequests > 0 ? round(($verifiedTotal / $totalRequests) * 100, 1) : 0.0,
                'verified_label_accuracy_rate' => $verifiedTotal > 0 ? round(($verifiedMatches / $verifiedTotal) * 100, 1) : 0.0,
                'verified_accuracy_by_method' => $verifiedAccuracyByMethod,
            ],
        ]);
    }
}
