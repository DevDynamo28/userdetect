<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\UserDetection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsDashboardController extends Controller
{
    public function index(Request $request)
    {
        $client = auth()->user();
        $period = $request->query('period', 'last_7_days');

        $query = UserDetection::forClient($client->id)->forPeriod($period);
        $driver = DB::connection()->getDriverName();
        $hourBucketExpression = match ($driver) {
            'pgsql' => "DATE_TRUNC('hour', detected_at)",
            'sqlite' => "strftime('%Y-%m-%d %H:00:00', detected_at)",
            default => "DATE_FORMAT(detected_at, '%Y-%m-%d %H:00:00')",
        };

        $totalRequests = (clone $query)->count();
        $uniqueUsers = (clone $query)->distinct('fingerprint_id')->count('fingerprint_id');
        $avgConfidence = (int) (clone $query)->avg('confidence') ?: 0;
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

        // Hourly breakdown
        $hourlyData = (clone $query)
            ->select(
                DB::raw("{$hourBucketExpression} as hour"),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('hour')
            ->orderBy('hour')
            ->pluck('count', 'hour')
            ->toArray();

        // Geographic distribution
        $geoData = (clone $query)
            ->whereNotNull('detected_city')
            ->select('detected_city as city', 'detected_state as state', DB::raw('COUNT(*) as count'))
            ->groupBy('detected_city', 'detected_state')
            ->orderByDesc('count')
            ->limit(20)
            ->get()
            ->toArray();

        // Method breakdown
        $methodData = (clone $query)
            ->select('detection_method', DB::raw('COUNT(*) as count'))
            ->groupBy('detection_method')
            ->pluck('count', 'detection_method')
            ->toArray();

        $cityHitByMethod = (clone $query)
            ->select(
                'detection_method',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN detected_city IS NOT NULL THEN 1 ELSE 0 END) as city_hits')
            )
            ->groupBy('detection_method')
            ->get()
            ->map(function ($row) {
                $total = (int) $row->total;
                $hits = (int) $row->city_hits;

                return [
                    'method' => $row->detection_method,
                    'total' => $total,
                    'city_hits' => $hits,
                    'city_hit_rate' => $total > 0 ? round(($hits / $total) * 100, 1) : 0.0,
                ];
            })
            ->toArray();

        $disagreementByMethod = (clone $query)
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
                $total = (int) $row->verified_total;
                $matches = (int) $row->verified_matches;

                return [
                    'method' => $row->detection_method,
                    'verified_total' => $total,
                    'verified_matches' => $matches,
                    'accuracy_rate' => $total > 0 ? round(($matches / $total) * 100, 1) : 0.0,
                ];
            })
            ->toArray();

        // VPN trend (daily)
        $vpnTrend = (clone $query)
            ->where('is_vpn', true)
            ->select(
                DB::raw("DATE(detected_at) as date"),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();

        // Confidence trend (daily avg)
        $confTrend = (clone $query)
            ->select(
                DB::raw("DATE(detected_at) as date"),
                DB::raw('AVG(confidence) as avg_conf')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('avg_conf', 'date')
            ->map(fn ($v) => round($v))
            ->toArray();

        $qualityMetrics = [
            'city_hit_rate' => $totalRequests > 0 ? round(($cityHitCount / $totalRequests) * 100, 1) : 0.0,
            'low_confidence_rate' => $totalRequests > 0 ? round(($lowConfidenceCount / $totalRequests) * 100, 1) : 0.0,
            'disagreement_rate' => $totalRequests > 0 ? round(($disagreementCount / $totalRequests) * 100, 1) : 0.0,
            'verified_label_coverage_rate' => $totalRequests > 0 ? round(($verifiedTotal / $totalRequests) * 100, 1) : 0.0,
            'verified_label_accuracy_rate' => $verifiedTotal > 0 ? round(($verifiedMatches / $verifiedTotal) * 100, 1) : 0.0,
        ];

        return view('dashboard.analytics', compact(
            'period',
            'totalRequests',
            'uniqueUsers',
            'avgConfidence',
            'hourlyData',
            'geoData',
            'methodData',
            'vpnTrend',
            'confTrend',
            'qualityMetrics',
            'cityHitByMethod',
            'disagreementByMethod',
            'verifiedAccuracyByMethod'
        ));
    }

    public function export(Request $request)
    {
        $client = auth()->user();
        $period = $request->query('period', 'last_7_days');
        $format = $request->query('format', 'csv');

        $detections = UserDetection::forClient($client->id)
            ->forPeriod($period)
            ->orderByDesc('detected_at')
            ->limit(10000)
            ->get();

        if ($format === 'json') {
            return response()->json($detections)
                ->header('Content-Disposition', 'attachment; filename="detections_export.json"');
        }

        // CSV export
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="detections_export.csv"',
        ];

        $callback = function () use ($detections) {
            $file = fopen('php://output', 'w');

            fputcsv($file, [
                'Detected At', 'Fingerprint ID', 'City', 'State', 'Country',
                'Confidence', 'Method', 'VPN', 'IP Address', 'ISP', 'Browser', 'OS',
            ]);

            foreach ($detections as $d) {
                fputcsv($file, [
                    $d->detected_at,
                    $d->fingerprint_id,
                    $d->detected_city,
                    $d->detected_state,
                    $d->detected_country,
                    $d->confidence,
                    $d->detection_method,
                    $d->is_vpn ? 'Yes' : 'No',
                    $d->ip_address,
                    $d->isp,
                    $d->browser,
                    $d->os,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
