<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['title', 'value', 'suffix' => '', 'color' => 'indigo']));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter((['title', 'value', 'suffix' => '', 'color' => 'indigo']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars); ?>

<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
    <p class="text-sm font-medium text-gray-500"><?php echo e($title); ?></p>
    <p class="text-3xl font-bold text-gray-900 mt-2">
        <?php echo e(number_format($value)); ?><?php echo e($suffix); ?>

    </p>
</div>
<?php /**PATH D:\IP Detect\resources\views/components/stat-card.blade.php ENDPATH**/ ?>