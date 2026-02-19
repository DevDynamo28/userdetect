@extends('layouts.dashboard')

@section('title', 'Documentation')
@section('page-title', 'Documentation')

@section('content')
<div class="max-w-4xl space-y-8">

    {{-- Quick Start --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Quick Start Guide</h2>
        <div class="prose prose-sm max-w-none text-gray-700">
            <p>Get started with UserDetect in 3 simple steps:</p>

            <h3 class="text-base font-medium text-gray-900 mt-4">Step 1: Add the SDK to your website</h3>
            <div class="bg-gray-900 rounded-lg p-4 overflow-x-auto my-3">
                <pre class="text-sm text-green-400"><code>&lt;script src="{{ url('/sdk/userdetect.min.js') }}"&gt;&lt;/script&gt;</code></pre>
            </div>

            <h3 class="text-base font-medium text-gray-900 mt-4">Step 2: Initialize with your API key</h3>
            <div class="bg-gray-900 rounded-lg p-4 overflow-x-auto my-3">
                <pre class="text-sm text-green-400"><code>UserDetect.init('YOUR_API_KEY', function(data) {
  if (data.success) {
    console.log('City:', data.location.city);
    console.log('State:', data.location.state);
    console.log('Confidence:', data.location.confidence + '%');
    console.log('VPN:', data.vpn_detection.is_vpn);
  } else {
    console.warn('Detection failed:', data.error.code, data.error.message);
  }
});</code></pre>
            </div>

            <h3 class="text-base font-medium text-gray-900 mt-4">Step 3: Use the location data</h3>
            <div class="bg-gray-900 rounded-lg p-4 overflow-x-auto my-3">
                <pre class="text-sm text-green-400"><code>UserDetect.init('YOUR_API_KEY', function(data) {
  if (data.success && data.location.city) {
    // Personalize content based on location
    document.getElementById('greeting').textContent =
      'Welcome from ' + data.location.city + '!';

    // Show city-specific offers
    if (data.location.city === 'Mumbai') {
      showMumbaiOffers();
    }
  }
});</code></pre>
            </div>
        </div>
    </div>

    {{-- API Reference --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">API Reference</h2>

        {{-- POST /detect --}}
        <div class="mb-8">
            <div class="flex items-center gap-2 mb-3">
                <span class="px-2 py-1 bg-green-100 text-green-800 text-xs font-mono rounded">POST</span>
                <code class="text-sm font-medium">/api/v1/detect</code>
            </div>
            <p class="text-sm text-gray-600 mb-3">Primary detection endpoint. Send user signals and receive location data.</p>

            <h4 class="text-sm font-medium text-gray-900 mt-4 mb-2">Headers</h4>
            <div class="bg-gray-50 rounded p-3 text-sm font-mono">
                X-API-Key: your_api_key<br>
                Content-Type: application/json
            </div>

            <h4 class="text-sm font-medium text-gray-900 mt-4 mb-2">Request Body</h4>
            <div class="bg-gray-900 rounded-lg p-4 overflow-x-auto">
                <pre class="text-sm text-green-400"><code>{
  "signals": {
    "fingerprint": "abc123def456...",
    "timezone": "Asia/Kolkata",
    "timezone_offset": -330,
    "language": "en-IN",
    "languages": ["en-IN", "hi"],
    "user_agent": "Mozilla/5.0...",
    "screen": {
      "width": 1920,
      "height": 1080,
      "color_depth": 24
    },
    "platform": "Win32"
  }
}</code></pre>
            </div>

            <h4 class="text-sm font-medium text-gray-900 mt-4 mb-2">Response</h4>
            <div class="bg-gray-900 rounded-lg p-4 overflow-x-auto">
                <pre class="text-sm text-green-400"><code>{
  "success": true,
  "request_id": "req_abc123",
  "user_id": "fp_abc123def456",
  "is_new_user": false,
  "location": {
    "city": "Ahmedabad",
    "state": "Gujarat",
    "country": "India",
    "confidence": 82,
    "method": "reverse_dns"
  },
  "vpn_detection": {
    "is_vpn": false,
    "confidence": 90,
    "indicators": []
  },
  "user_history": {
    "visit_count": 5,
    "trust_score": 75
  },
  "timestamp": "2026-02-17T14:30:00Z"
}</code></pre>
            </div>
        </div>

        {{-- GET /analytics/summary --}}
        <div class="mb-8">
            <div class="flex items-center gap-2 mb-3">
                <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs font-mono rounded">GET</span>
                <code class="text-sm font-medium">/api/v1/analytics/summary</code>
            </div>
            <p class="text-sm text-gray-600 mb-3">Get usage analytics for your account.</p>
            <p class="text-sm text-gray-600">Query params: <code class="bg-gray-100 px-1 rounded">period=last_24_hours|last_7_days|last_30_days</code></p>
        </div>

        {{-- GET /user/{id}/history --}}
        <div class="mb-8">
            <div class="flex items-center gap-2 mb-3">
                <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs font-mono rounded">GET</span>
                <code class="text-sm font-medium">/api/v1/user/{fingerprint_id}/history</code>
            </div>
            <p class="text-sm text-gray-600 mb-3">Get historical data for a specific user.</p>
        </div>

        {{-- Error Codes --}}
        <div>
            <h3 class="text-base font-medium text-gray-900 mb-3">Error Codes</h3>
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="bg-gray-50">
                        <th class="px-4 py-2 text-left border">HTTP Code</th>
                        <th class="px-4 py-2 text-left border">Error Code</th>
                        <th class="px-4 py-2 text-left border">Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td class="px-4 py-2 border">401</td><td class="px-4 py-2 border font-mono text-xs">MISSING_API_KEY</td><td class="px-4 py-2 border">No API key provided</td></tr>
                    <tr><td class="px-4 py-2 border">401</td><td class="px-4 py-2 border font-mono text-xs">INVALID_API_KEY</td><td class="px-4 py-2 border">API key is invalid or inactive</td></tr>
                    <tr><td class="px-4 py-2 border">422</td><td class="px-4 py-2 border font-mono text-xs">VALIDATION_ERROR</td><td class="px-4 py-2 border">Request validation failed</td></tr>
                    <tr><td class="px-4 py-2 border">429</td><td class="px-4 py-2 border font-mono text-xs">RATE_LIMIT_EXCEEDED</td><td class="px-4 py-2 border">Too many requests</td></tr>
                    <tr><td class="px-4 py-2 border">503</td><td class="px-4 py-2 border font-mono text-xs">SERVICE_UNAVAILABLE</td><td class="px-4 py-2 border">Temporary auth/dependency outage</td></tr>
                    <tr><td class="px-4 py-2 border">5xx</td><td class="px-4 py-2 border font-mono text-xs">WORKER_CONFIG_ERROR / LOOP_DETECTED / ORIGIN_UNREACHABLE</td><td class="px-4 py-2 border">Cloudflare edge routing or origin connectivity issue</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- SDK Methods --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">SDK Methods</h2>

        <div class="space-y-6 text-sm">
            <div>
                <code class="text-indigo-600 font-medium">UserDetect.init(apiKey, callback, options?)</code>
                <p class="text-gray-600 mt-1">Initialize the SDK and auto-detect location.</p>
            </div>

            <div>
                <code class="text-indigo-600 font-medium">UserDetect.detect()</code>
                <p class="text-gray-600 mt-1">Manually trigger detection. Returns a Promise.</p>
            </div>

            <div>
                <code class="text-indigo-600 font-medium">UserDetect.getUserId()</code>
                <p class="text-gray-600 mt-1">Get the current user's fingerprint ID.</p>
            </div>

            <div>
                <code class="text-indigo-600 font-medium">UserDetect.getLastDetection()</code>
                <p class="text-gray-600 mt-1">Get the cached last detection result.</p>
            </div>
        </div>

        <h3 class="text-base font-medium text-gray-900 mt-6 mb-3">Options</h3>
        <div class="bg-gray-900 rounded-lg p-4 overflow-x-auto">
            <pre class="text-sm text-green-400"><code>{
  apiEndpoint: '{{ url('/api') }}',  // Recommended: explicitly set your API base URL
  timeout: 10000,                    // Request timeout (ms)
  debug: false,                      // Enable console logging
  autoDetect: true,                  // Auto-run on init
  onEvent: (event) => {},            // Optional SDK telemetry hook
}</code></pre>
        </div>
    </div>

    {{-- React/Vue Examples --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Framework Integration Examples</h2>

        <h3 class="text-base font-medium text-gray-900 mb-3">React</h3>
        <div class="bg-gray-900 rounded-lg p-4 overflow-x-auto mb-6">
            <pre class="text-sm text-green-400"><code>import { useEffect, useState } from 'react';

function App() {
  const [location, setLocation] = useState(null);

  useEffect(() => {
    UserDetect.init('YOUR_API_KEY', (data) => {
      if (data.success) {
        setLocation(data.location);
      }
    });
  }, []);

  return (
    &lt;div&gt;
      {location && &lt;p&gt;Detected: {location.city}, {location.state}&lt;/p&gt;}
    &lt;/div&gt;
  );
}</code></pre>
        </div>

        <h3 class="text-base font-medium text-gray-900 mb-3">Vue.js</h3>
        <div class="bg-gray-900 rounded-lg p-4 overflow-x-auto">
            <pre class="text-sm text-green-400"><code>&lt;template&gt;
  &lt;div v-if="location"&gt;
    Detected: @{{ location.city }}, @{{ location.state }}
  &lt;/div&gt;
&lt;/template&gt;

&lt;script&gt;
export default {
  data() {
    return { location: null };
  },
  mounted() {
    UserDetect.init('YOUR_API_KEY', (data) => {
      if (data.success) {
        this.location = data.location;
      }
    });
  }
};
&lt;/script&gt;</code></pre>
        </div>
    </div>

    {{-- FAQ --}}
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">FAQs</h2>

        <div class="space-y-4" x-data="{ open: null }">
            @foreach([
                ['How accurate is the detection?', 'First-time visitors: 70-85% accuracy at city level. Returning visitors: 85-90%+ as the system learns patterns.'],
                ['Does it work with VPNs?', 'The system detects VPN usage and flags it. When a VPN is detected, confidence scores are reduced and the response includes VPN indicators.'],
                ['What about privacy?', 'We do not store personally identifiable information. Fingerprints are hashed and not reversible. Default data retention is 90 days.'],
                ['What are the rate limits?', 'Free: 100 req/min, Starter: 500 req/min, Growth: 2000 req/min. Contact us for enterprise limits.'],
                ['Which countries are supported?', 'Currently optimized for India with 50+ city coverage. Support for other countries is on the roadmap.'],
            ] as $i => [$q, $a])
            <div class="border border-gray-200 rounded-lg">
                <button @click="open = open === {{ $i }} ? null : {{ $i }}"
                        class="w-full text-left px-4 py-3 flex items-center justify-between text-sm font-medium text-gray-900">
                    {{ $q }}
                    <svg class="w-4 h-4 text-gray-400 transition-transform" :class="{ 'rotate-180': open === {{ $i }} }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>
                <div x-show="open === {{ $i }}" x-cloak class="px-4 pb-3 text-sm text-gray-600">
                    {{ $a }}
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
