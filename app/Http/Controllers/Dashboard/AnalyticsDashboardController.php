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

        $totalRequests = (clone $query)->count();
        $uniqueUsers = (clone $query)->distinct('fingerprint_id')->count('fingerprint_id');
        $avgConfidence = (int) (clone $query)->avg('confidence') ?: 0;

        // Hourly breakdown
        $hourlyData = (clone $query)
            ->select(
                DB::raw("DATE_TRUNC('hour', detected_at) as hour"),
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

        return view('dashboard.analytics', compact(
            'period',
            'totalRequests',
            'uniqueUsers',
            'avgConfidence',
            'hourlyData',
            'geoData',
            'methodData',
            'vpnTrend',
            'confTrend'
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
