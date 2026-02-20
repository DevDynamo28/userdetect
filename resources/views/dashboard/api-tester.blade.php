@extends('layouts.dashboard')

@section('title', 'API Tester')
@section('page-title', 'API Tester')

@section('content')
<div x-data="apiTester()" class="space-y-6">
    {{-- Input Section --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-4">Test IP Detection</h3>
        <div class="flex flex-col sm:flex-row gap-4">
            <div class="flex-1">
                <input
                    type="text"
                    x-model="ipAddress"
                    placeholder="Enter IP address (e.g. 106.215.153.11)"
                    class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
                    @keydown.enter="runTest()"
                >
            </div>
            <button
                @click="runTest()"
                :disabled="loading || !ipAddress.trim()"
                class="px-6 py-2.5 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center min-w-[120px]"
            >
                <template x-if="loading">
                    <svg class="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                </template>
                <span x-text="loading ? 'Detecting...' : 'Test'"></span>
            </button>
        </div>

        {{-- Quick Fill Buttons --}}
        <div class="mt-3 flex flex-wrap gap-2">
            <span class="text-xs text-gray-500 py-1">Quick fill:</span>
            <button @click="ipAddress = '106.215.153.11'" class="text-xs px-3 py-1 bg-gray-100 text-gray-600 rounded-full hover:bg-gray-200">Airtel IN</button>
            <button @click="ipAddress = '49.36.128.1'" class="text-xs px-3 py-1 bg-gray-100 text-gray-600 rounded-full hover:bg-gray-200">Jio IN</button>
            <button @click="ipAddress = '8.8.8.8'" class="text-xs px-3 py-1 bg-gray-100 text-gray-600 rounded-full hover:bg-gray-200">Google DNS</button>
            <button @click="ipAddress = '1.1.1.1'" class="text-xs px-3 py-1 bg-gray-100 text-gray-600 rounded-full hover:bg-gray-200">Cloudflare DNS</button>
            <button @click="ipAddress = '103.21.244.0'" class="text-xs px-3 py-1 bg-gray-100 text-gray-600 rounded-full hover:bg-gray-200">VPN Test</button>
        </div>
    </div>

    {{-- Error Message --}}
    <template x-if="error">
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            <p class="text-sm" x-text="error"></p>
        </div>
    </template>

    {{-- Results Section --}}
    <template x-if="result">
        <div class="space-y-6">
            {{-- Summary Cards --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">City</p>
                    <p class="mt-1 text-xl font-semibold text-gray-900" x-text="result.location?.city || 'Unknown'"></p>
                </div>
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">State</p>
                    <p class="mt-1 text-xl font-semibold text-gray-900" x-text="result.location?.state || 'Unknown'"></p>
                </div>
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Confidence</p>
                    <p class="mt-1 text-xl font-semibold" :class="confidenceColor(result.location?.confidence)">
                        <span x-text="result.location?.confidence ?? 0"></span>%
                    </p>
                </div>
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Method</p>
                    <p class="mt-1 text-xl font-semibold text-gray-900" x-text="result.location?.method || 'none'"></p>
                </div>
            </div>

            {{-- Detail Cards --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Location Details --}}
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h4 class="text-sm font-semibold text-gray-700 mb-4 uppercase tracking-wide">Location Details</h4>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-500">Country</dt>
                            <dd class="text-sm font-medium text-gray-900" x-text="result.location?.country || '-'"></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-500">State</dt>
                            <dd class="text-sm font-medium text-gray-900" x-text="result.location?.state || '-'"></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-500">City</dt>
                            <dd class="text-sm font-medium text-gray-900" x-text="result.location?.city || '-'"></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-500">Confidence</dt>
                            <dd class="text-sm font-medium" :class="confidenceColor(result.location?.confidence)" x-text="(result.location?.confidence ?? 0) + '%'"></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-500">Method</dt>
                            <dd class="text-sm font-medium text-gray-900" x-text="result.location?.method || '-'"></dd>
                        </div>
                        <template x-if="result.location?.note">
                            <div class="flex justify-between">
                                <dt class="text-sm text-gray-500">Note</dt>
                                <dd class="text-sm font-medium text-amber-600" x-text="result.location.note"></dd>
                            </div>
                        </template>
                    </dl>
                </div>

                {{-- VPN Detection --}}
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h4 class="text-sm font-semibold text-gray-700 mb-4 uppercase tracking-wide">VPN Detection</h4>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-500">VPN Detected</dt>
                            <dd>
                                <span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                    :class="result.vpn_detection?.is_vpn ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'"
                                    x-text="result.vpn_detection?.is_vpn ? 'Yes' : 'No'"
                                ></span>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-500">VPN Confidence</dt>
                            <dd class="text-sm font-medium text-gray-900" x-text="(result.vpn_detection?.confidence ?? 0) + '%'"></dd>
                        </div>
                        <template x-if="result.vpn_detection?.indicators?.length > 0">
                            <div>
                                <dt class="text-sm text-gray-500 mb-2">Indicators</dt>
                                <dd class="flex flex-wrap gap-1">
                                    <template x-for="indicator in result.vpn_detection.indicators" :key="indicator">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800" x-text="indicator"></span>
                                    </template>
                                </dd>
                            </div>
                        </template>
                    </dl>
                </div>
            </div>

            {{-- Additional Info --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Request Info --}}
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h4 class="text-sm font-semibold text-gray-700 mb-4 uppercase tracking-wide">Request Info</h4>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-500">Request ID</dt>
                            <dd class="text-sm font-mono text-gray-900" x-text="result.request_id || '-'"></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-500">Processing Time</dt>
                            <dd class="text-sm font-medium text-gray-900" x-text="(result.processing_time_ms ?? '-') + ' ms'"></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-500">Recommendation</dt>
                            <dd class="text-sm font-medium text-gray-900" x-text="result.recommendation || 'none'"></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-gray-500">Timestamp</dt>
                            <dd class="text-sm font-medium text-gray-900" x-text="result.timestamp || '-'"></dd>
                        </div>
                    </dl>
                </div>

                {{-- Alternatives --}}
                <template x-if="result.alternatives?.length > 0">
                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h4 class="text-sm font-semibold text-gray-700 mb-4 uppercase tracking-wide">Alternative Locations</h4>
                        <div class="space-y-2">
                            <template x-for="alt in result.alternatives" :key="alt.city">
                                <div class="flex justify-between items-center py-2 border-b border-gray-100 last:border-0">
                                    <span class="text-sm text-gray-900" x-text="(alt.city || 'Unknown') + ', ' + (alt.state || '')"></span>
                                    <span class="text-sm font-medium text-gray-500" x-text="(alt.confidence || 0) + '%'"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Raw JSON Toggle --}}
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6" x-data="{ showRaw: false }">
                <button @click="showRaw = !showRaw" class="flex items-center text-sm font-semibold text-gray-700 uppercase tracking-wide">
                    <svg class="w-4 h-4 mr-2 transition-transform" :class="{ 'rotate-90': showRaw }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                    Raw JSON Response
                </button>
                <div x-show="showRaw" x-collapse class="mt-4">
                    <pre class="bg-gray-900 text-green-400 p-4 rounded-lg text-xs overflow-x-auto max-h-96" x-text="JSON.stringify(result, null, 2)"></pre>
                </div>
            </div>
        </div>
    </template>
</div>
@endsection

@push('scripts')
<script>
function apiTester() {
    return {
        ipAddress: '',
        loading: false,
        result: null,
        error: null,

        async runTest() {
            if (!this.ipAddress.trim()) return;

            this.loading = true;
            this.error = null;
            this.result = null;

            try {
                const response = await fetch('{{ route("dashboard.api-tester.test") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ ip: this.ipAddress.trim() }),
                });

                const data = await response.json();

                if (!response.ok) {
                    this.error = data.error?.message || data.message || 'Request failed with status ' + response.status;
                    return;
                }

                this.result = data;
            } catch (e) {
                this.error = 'Network error: ' + e.message;
            } finally {
                this.loading = false;
            }
        },

        confidenceColor(confidence) {
            if (confidence >= 75) return 'text-green-600';
            if (confidence >= 50) return 'text-amber-600';
            return 'text-red-600';
        }
    };
}
</script>
@endpush
