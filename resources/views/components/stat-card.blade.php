@props(['title', 'value', 'suffix' => '', 'color' => 'indigo'])

<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
    <p class="text-sm font-medium text-gray-500">{{ $title }}</p>
    <p class="text-3xl font-bold text-gray-900 mt-2">
        {{ number_format($value) }}{{ $suffix }}
    </p>
</div>
