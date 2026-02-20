@extends('layouts.dashboard')

@section('title', 'Analytics')
@section('page-title', 'Analytics')

@section('content')
{{-- Filters --}}
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6" x-data="{ period: '{{ $period }}' }">
    <div class="flex flex-wrap items-center gap-4">
        <span class="text-sm font-medium text-gray-700">Period:</span>
        <div class="flex gap-2">
            @foreach(['last_24_hours' => 'Last 24h', 'last_7_days' => 'Last 7 Days', 'last_30_days' => 'Last 30 Days'] as $value => $label)
                <a href="{{ route('dashboard.analytics', ['period' => $value]) }}"
                   class="px-3 py-1.5 rounded-lg text-sm font-medium transition
                       {{ $period === $value ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <div class="ml-auto flex gap-2">
            <a href="{{ route('dashboard.analytics.export', ['period' => $period, 'format' => 'csv']) }}"
               class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">
                Export CSV
            </a>
            <a href="{{ route('dashboard.analytics.export', ['period' => $period, 'format' => 'json']) }}"
               class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">
                Export JSON
            </a>
        </div>
    </div>
</div>

{{-- Summary Stats --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <x-stat-card title="Total Requests" :value="$totalRequests" />
    <x-stat-card title="Unique Users" :value="$uniqueUsers" />
    <x-stat-card title="Avg Confidence" :value="$avgConfidence" suffix="%" />
</div>

{{-- Quality Metrics --}}
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
    <x-stat-card title="City Hit Rate" :value="$qualityMetrics['city_hit_rate']" suffix="%" />
    <x-stat-card title="Low Confidence Rate" :value="$qualityMetrics['low_confidence_rate']" suffix="%" />
    <x-stat-card title="Disagreement Rate" :value="$qualityMetrics['disagreement_rate']" suffix="%" />
    <x-stat-card title="Verified Coverage" :value="$qualityMetrics['verified_label_coverage_rate']" suffix="%" />
    <x-stat-card title="Verified Accuracy" :value="$qualityMetrics['verified_label_accuracy_rate']" suffix="%" />
</div>

{{-- Charts --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    {{-- Requests Over Time --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-medium text-gray-700 mb-4">Requests Over Time</h3>
        <canvas id="hourlyChart" height="200"></canvas>
    </div>

    {{-- Detection Method Breakdown --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-medium text-gray-700 mb-4">Detection Methods</h3>
        <canvas id="methodChart" height="200"></canvas>
    </div>

    {{-- VPN Trend --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-medium text-gray-700 mb-4">VPN Detections</h3>
        <canvas id="vpnChart" height="200"></canvas>
    </div>

    {{-- Confidence Trend --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-medium text-gray-700 mb-4">Confidence Score Trend</h3>
        <canvas id="confChart" height="200"></canvas>
    </div>
</div>

{{-- Method Quality Breakdown --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-medium text-gray-700 mb-4">City Hit Rate by Method</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">City Hits</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($cityHitByMethod as $row)
                        <tr>
                            <td class="px-4 py-3 text-gray-900">{{ $row['method'] }}</td>
                            <td class="px-4 py-3 text-gray-900">{{ number_format($row['city_hits']) }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ number_format($row['total']) }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $row['city_hit_rate'] }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-gray-500">No data available</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-medium text-gray-700 mb-4">Disagreement Rate by Method</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Disagreements</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($disagreementByMethod as $row)
                        <tr>
                            <td class="px-4 py-3 text-gray-900">{{ $row['method'] }}</td>
                            <td class="px-4 py-3 text-gray-900">{{ number_format($row['disagreements']) }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ number_format($row['total']) }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $row['disagreement_rate'] }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-gray-500">No data available</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Verified Label Accuracy by Method --}}
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-8">
    <h3 class="text-sm font-medium text-gray-700 mb-4">Verified Label Accuracy by Method</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Verified Matches</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Verified Total</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Accuracy</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($verifiedAccuracyByMethod as $row)
                    <tr>
                        <td class="px-4 py-3 text-gray-900">{{ $row['method'] }}</td>
                        <td class="px-4 py-3 text-gray-900">{{ number_format($row['verified_matches']) }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ number_format($row['verified_total']) }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $row['accuracy_rate'] }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-3 text-gray-500">No verified labels captured for this period</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Geographic Distribution --}}
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
    <h3 class="text-sm font-medium text-gray-700 mb-4">Geographic Distribution</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">City</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">State</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Detections</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Percentage</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @foreach($geoData as $i => $geo)
                <tr>
                    <td class="px-4 py-3 text-gray-500">{{ $i + 1 }}</td>
                    <td class="px-4 py-3 text-gray-900 font-medium">{{ $geo['city'] }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $geo['state'] }}</td>
                    <td class="px-4 py-3 text-gray-900">{{ number_format($geo['count']) }}</td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <div class="w-24 bg-gray-200 rounded-full h-2">
                                <div class="bg-indigo-600 h-2 rounded-full" style="width: {{ $totalRequests > 0 ? round(($geo['count'] / $totalRequests) * 100) : 0 }}%"></div>
                            </div>
                            <span class="text-gray-600 text-xs">{{ $totalRequests > 0 ? round(($geo['count'] / $totalRequests) * 100, 1) : 0 }}%</span>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const hourlyData = @json($hourlyData);
    const methodData = @json($methodData);
    const vpnTrend = @json($vpnTrend);
    const confTrend = @json($confTrend);

    // Hourly Chart
    new Chart(document.getElementById('hourlyChart'), {
        type: 'line',
        data: {
            labels: Object.keys(hourlyData).map(h => h.substring(11, 16)),
            datasets: [{
                label: 'Requests',
                data: Object.values(hourlyData),
                borderColor: '#6366f1',
                backgroundColor: 'rgba(99, 102, 241, 0.1)',
                fill: true,
                tension: 0.3,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });

    // Method Chart
    const methodColors = {
        'reverse_dns': '#22c55e',
        'ensemble_ip': '#6366f1',
        'fingerprint_history': '#eab308',
        'ip_range_learning': '#8b5cf6',
        'unknown': '#9ca3af'
    };

    new Chart(document.getElementById('methodChart'), {
        type: 'doughnut',
        data: {
            labels: Object.keys(methodData),
            datasets: [{
                data: Object.values(methodData),
                backgroundColor: Object.keys(methodData).map(m => methodColors[m] || '#9ca3af'),
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'right' } }
        }
    });

    // VPN Trend
    new Chart(document.getElementById('vpnChart'), {
        type: 'bar',
        data: {
            labels: Object.keys(vpnTrend),
            datasets: [{
                label: 'VPN Detections',
                data: Object.values(vpnTrend),
                backgroundColor: '#ef4444',
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });

    // Confidence Trend
    new Chart(document.getElementById('confChart'), {
        type: 'line',
        data: {
            labels: Object.keys(confTrend),
            datasets: [{
                label: 'Avg Confidence',
                data: Object.values(confTrend),
                borderColor: '#22c55e',
                backgroundColor: 'rgba(34, 197, 94, 0.1)',
                fill: true,
                tension: 0.3,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { min: 0, max: 100 } }
        }
    });
});
</script>
@endpush
