@extends('layouts.dashboard')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
{{-- Stat Cards --}}
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <x-stat-card title="Total API Calls Today" :value="$stats['total_calls']" />
    <x-stat-card title="Unique Users Today" :value="$stats['unique_users']" />
    <x-stat-card title="Average Confidence" :value="$stats['avg_confidence']" suffix="%" />
    <x-stat-card title="VPN Detection Rate" :value="$stats['vpn_rate']" suffix="%" />
</div>

{{-- Charts Row --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    {{-- API Calls Over Time --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-medium text-gray-700 mb-4">API Calls - Last 7 Days</h3>
        <canvas id="callsChart" height="200"></canvas>
    </div>

    {{-- Top Cities Pie --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-medium text-gray-700 mb-4">Top Cities</h3>
        <canvas id="citiesChart" height="200"></canvas>
    </div>
</div>

{{-- Confidence Distribution --}}
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-8">
    <h3 class="text-sm font-medium text-gray-700 mb-4">Confidence Distribution - Last 7 Days</h3>
    <div class="max-w-md mx-auto">
        <canvas id="confidenceChart" height="150"></canvas>
    </div>
</div>

{{-- Recent Detections --}}
<div class="bg-white rounded-lg shadow-sm border border-gray-200">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-sm font-medium text-gray-700">Recent Detections</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">User ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">City</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">State</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Confidence</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">VPN</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($recentDetections as $detection)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-3 text-gray-600">{{ $detection->detected_at->format('H:i:s') }}</td>
                    <td class="px-6 py-3 text-gray-900 font-mono text-xs">{{ Str::limit($detection->fingerprint_id, 12) }}</td>
                    <td class="px-6 py-3 text-gray-900">{{ $detection->detected_city ?? '-' }}</td>
                    <td class="px-6 py-3 text-gray-600">{{ $detection->detected_state ?? '-' }}</td>
                    <td class="px-6 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                            {{ $detection->confidence >= 80 ? 'bg-green-100 text-green-800' : ($detection->confidence >= 60 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}">
                            {{ $detection->confidence }}%
                        </span>
                    </td>
                    <td class="px-6 py-3">
                        @if($detection->is_vpn)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">Yes</span>
                        @else
                            <span class="text-gray-400">No</span>
                        @endif
                    </td>
                    <td class="px-6 py-3 text-gray-600 text-xs">{{ $detection->detection_method }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-6 py-8 text-center text-gray-400">No detections yet. Integrate the SDK to start detecting.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chartData = @json($chartData);
    const topCities = @json($topCities);
    const confDist = @json($confidenceDistribution);

    // API Calls Line Chart
    new Chart(document.getElementById('callsChart'), {
        type: 'line',
        data: {
            labels: Object.keys(chartData),
            datasets: [{
                label: 'API Calls',
                data: Object.values(chartData),
                borderColor: '#6366f1',
                backgroundColor: 'rgba(99, 102, 241, 0.1)',
                fill: true,
                tension: 0.3,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 } }
            }
        }
    });

    // Top Cities Pie Chart
    const cityLabels = topCities.map(c => c.city);
    const cityCounts = topCities.map(c => c.count);
    const cityColors = ['#6366f1', '#8b5cf6', '#a78bfa', '#c4b5fd', '#ddd6fe'];

    new Chart(document.getElementById('citiesChart'), {
        type: 'doughnut',
        data: {
            labels: cityLabels,
            datasets: [{
                data: cityCounts,
                backgroundColor: cityColors,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'right' }
            }
        }
    });

    // Confidence Distribution Bar Chart
    new Chart(document.getElementById('confidenceChart'), {
        type: 'bar',
        data: {
            labels: ['High (80-100)', 'Medium (60-79)', 'Low (0-59)'],
            datasets: [{
                label: 'Detections',
                data: [confDist.high, confDist.medium, confDist.low],
                backgroundColor: ['#22c55e', '#eab308', '#ef4444'],
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 } }
            }
        }
    });
});
</script>
@endpush
