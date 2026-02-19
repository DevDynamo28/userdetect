<?php $__env->startSection('title', 'Analytics'); ?>
<?php $__env->startSection('page-title', 'Analytics'); ?>

<?php $__env->startSection('content'); ?>

<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6" x-data="{ period: '<?php echo e($period); ?>' }">
    <div class="flex flex-wrap items-center gap-4">
        <span class="text-sm font-medium text-gray-700">Period:</span>
        <div class="flex gap-2">
            <?php $__currentLoopData = ['last_24_hours' => 'Last 24h', 'last_7_days' => 'Last 7 Days', 'last_30_days' => 'Last 30 Days']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $value => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <a href="<?php echo e(route('dashboard.analytics', ['period' => $value])); ?>"
                   class="px-3 py-1.5 rounded-lg text-sm font-medium transition
                       <?php echo e($period === $value ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'); ?>">
                    <?php echo e($label); ?>

                </a>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>

        <div class="ml-auto flex gap-2">
            <a href="<?php echo e(route('dashboard.analytics.export', ['period' => $period, 'format' => 'csv'])); ?>"
               class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">
                Export CSV
            </a>
            <a href="<?php echo e(route('dashboard.analytics.export', ['period' => $period, 'format' => 'json'])); ?>"
               class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200 transition">
                Export JSON
            </a>
        </div>
    </div>
</div>


<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <?php if (isset($component)) { $__componentOriginal527fae77f4db36afc8c8b7e9f5f81682 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal527fae77f4db36afc8c8b7e9f5f81682 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.stat-card','data' => ['title' => 'Total Requests','value' => $totalRequests]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('stat-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Total Requests','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($totalRequests)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal527fae77f4db36afc8c8b7e9f5f81682)): ?>
<?php $attributes = $__attributesOriginal527fae77f4db36afc8c8b7e9f5f81682; ?>
<?php unset($__attributesOriginal527fae77f4db36afc8c8b7e9f5f81682); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal527fae77f4db36afc8c8b7e9f5f81682)): ?>
<?php $component = $__componentOriginal527fae77f4db36afc8c8b7e9f5f81682; ?>
<?php unset($__componentOriginal527fae77f4db36afc8c8b7e9f5f81682); ?>
<?php endif; ?>
    <?php if (isset($component)) { $__componentOriginal527fae77f4db36afc8c8b7e9f5f81682 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal527fae77f4db36afc8c8b7e9f5f81682 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.stat-card','data' => ['title' => 'Unique Users','value' => $uniqueUsers]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('stat-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Unique Users','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($uniqueUsers)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal527fae77f4db36afc8c8b7e9f5f81682)): ?>
<?php $attributes = $__attributesOriginal527fae77f4db36afc8c8b7e9f5f81682; ?>
<?php unset($__attributesOriginal527fae77f4db36afc8c8b7e9f5f81682); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal527fae77f4db36afc8c8b7e9f5f81682)): ?>
<?php $component = $__componentOriginal527fae77f4db36afc8c8b7e9f5f81682; ?>
<?php unset($__componentOriginal527fae77f4db36afc8c8b7e9f5f81682); ?>
<?php endif; ?>
    <?php if (isset($component)) { $__componentOriginal527fae77f4db36afc8c8b7e9f5f81682 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal527fae77f4db36afc8c8b7e9f5f81682 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.stat-card','data' => ['title' => 'Avg Confidence','value' => $avgConfidence,'suffix' => '%']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('stat-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Avg Confidence','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($avgConfidence),'suffix' => '%']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal527fae77f4db36afc8c8b7e9f5f81682)): ?>
<?php $attributes = $__attributesOriginal527fae77f4db36afc8c8b7e9f5f81682; ?>
<?php unset($__attributesOriginal527fae77f4db36afc8c8b7e9f5f81682); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal527fae77f4db36afc8c8b7e9f5f81682)): ?>
<?php $component = $__componentOriginal527fae77f4db36afc8c8b7e9f5f81682; ?>
<?php unset($__componentOriginal527fae77f4db36afc8c8b7e9f5f81682); ?>
<?php endif; ?>
</div>


<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-medium text-gray-700 mb-4">Requests Over Time</h3>
        <canvas id="hourlyChart" height="200"></canvas>
    </div>

    
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-medium text-gray-700 mb-4">Detection Methods</h3>
        <canvas id="methodChart" height="200"></canvas>
    </div>

    
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-medium text-gray-700 mb-4">VPN Detections</h3>
        <canvas id="vpnChart" height="200"></canvas>
    </div>

    
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-medium text-gray-700 mb-4">Confidence Score Trend</h3>
        <canvas id="confChart" height="200"></canvas>
    </div>
</div>


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
                <?php $__currentLoopData = $geoData; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $geo): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <tr>
                    <td class="px-4 py-3 text-gray-500"><?php echo e($i + 1); ?></td>
                    <td class="px-4 py-3 text-gray-900 font-medium"><?php echo e($geo['city']); ?></td>
                    <td class="px-4 py-3 text-gray-600"><?php echo e($geo['state']); ?></td>
                    <td class="px-4 py-3 text-gray-900"><?php echo e(number_format($geo['count'])); ?></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <div class="w-24 bg-gray-200 rounded-full h-2">
                                <div class="bg-indigo-600 h-2 rounded-full" style="width: <?php echo e($totalRequests > 0 ? round(($geo['count'] / $totalRequests) * 100) : 0); ?>%"></div>
                            </div>
                            <span class="text-gray-600 text-xs"><?php echo e($totalRequests > 0 ? round(($geo['count'] / $totalRequests) * 100, 1) : 0); ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startPush('scripts'); ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const hourlyData = <?php echo json_encode($hourlyData, 15, 512) ?>;
    const methodData = <?php echo json_encode($methodData, 15, 512) ?>;
    const vpnTrend = <?php echo json_encode($vpnTrend, 15, 512) ?>;
    const confTrend = <?php echo json_encode($confTrend, 15, 512) ?>;

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
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.dashboard', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH D:\IP Detect\resources\views/dashboard/analytics.blade.php ENDPATH**/ ?>