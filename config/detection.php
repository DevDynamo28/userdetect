<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Detection Methods (Priority Order)
    |--------------------------------------------------------------------------
    */
    'methods' => [
        'signal_fusion' => [
            'enabled' => true,
            'priority' => 0,
        ],
        'local_geoip' => [
            'enabled' => true,
            'database_path' => storage_path('geoip/GeoLite2-City.mmdb'),
            'maxmind_license_key' => env('MAXMIND_LICENSE_KEY', ''),
            // GeoLite2 free DB achieves ~65-75% city-level accuracy for India.
            // The paid GeoIP2 City DB can use 85 here instead.
            'confidence' => 70,
        ],
        'reverse_dns' => [
            'enabled' => true,
            'confidence' => 88,
            'priority' => 1,
        ],
        'ensemble_ip' => [
            'enabled' => true,
            'confidence_base' => 75,
            'priority' => 2,
            'timeout' => (int) env('ENSEMBLE_TIMEOUT_SECONDS', 3), // seconds per API
            'connect_timeout' => (int) env('ENSEMBLE_CONNECT_TIMEOUT_SECONDS', 2), // network connect timeout per API
            'geo_cluster_radius_km' => 50, // cities within this radius are treated as same location
            'min_sources_required' => (int) env('ENSEMBLE_MIN_SOURCES_REQUIRED', 2), // below this, confidence is capped
            'failure_circuit_threshold' => (int) env('ENSEMBLE_FAILURE_CIRCUIT_THRESHOLD', 4), // consecutive failures to open circuit
            'failure_circuit_ttl_seconds' => (int) env('ENSEMBLE_FAILURE_CIRCUIT_TTL_SECONDS', 120), // skip external calls while circuit open
            // Keep false in production to avoid plaintext API calls.
            'allow_insecure_sources' => (bool) env('ENSEMBLE_ALLOW_INSECURE_SOURCES', false),
            // Restrict to compliant/reliable defaults. Additional sources can be enabled per environment.
            'enabled_sources' => array_values(array_filter(array_map('trim', explode(',', env(
                'ENSEMBLE_ENABLED_SOURCES',
                'ipapi,ipwhois,ipwho,freeipapi'
            ))))),
            'sources' => [
                'ipapi' => 'https://ipapi.co/{ip}/json/',
                'ip-api' => 'http://ip-api.com/json/{ip}?fields=status,message,country,countryCode,region,regionName,city,zip,lat,lon,timezone,isp,org,as',
                'geoplugin' => 'http://www.geoplugin.net/json.gp?ip={ip}',
                'ipwhois' => 'https://ipwhois.app/json/{ip}',
                'ipwho' => 'https://ipwho.is/{ip}',
                'freeipapi' => 'https://freeipapi.com/api/json/{ip}',
            ],
            // Reliability weights per source (higher = more trusted for Indian IPs)
            'source_weights' => [
                'ip-api' => 1.5,
                'ipwho' => 1.3,
                'ipapi' => 1.0,
                'ipwhois' => 1.0,
                'freeipapi' => 0.8,
                'geoplugin' => 0.6,
            ],
        ],
        'fingerprint_history' => [
            'enabled' => true,
            'confidence_boost' => 15,
            'min_visits' => 3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | VPN Detection
    |--------------------------------------------------------------------------
    */
    'vpn_detection' => [
        'enabled' => true,
        'confidence_penalty' => 20,
        'datacenter_asns' => [
            'AS16509',  // Amazon AWS
            'AS14061',  // DigitalOcean
            'AS16276',  // OVH
            'AS13335',  // Cloudflare
            'AS8075',   // Microsoft Azure
            'AS15169',  // Google Cloud
            'AS396982', // Google Cloud
            'AS20473',  // Vultr
            'AS63949',  // Linode/Akamai
            'AS24940',  // Hetzner
            'AS9009',   // M247 (VPN provider)
            'AS174',    // Cogent (often VPN)
            'AS6939',   // Hurricane Electric
            'AS209',    // CenturyLink
            'AS3223',   // Voxility (VPN hosting)
            'AS62563',  // SharkTech
            'AS398101', // GoDaddy
            'AS46606',  // Unified Layer
            'AS36352',  // ColoCrossing
            'AS55286',  // ServerMania
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-Learning Configuration
    |--------------------------------------------------------------------------
    */
    'learning' => [
        'enabled' => true,
        'min_confidence_to_learn' => 80,
        // Prevent model feedback loops. Learn only from verified labels by default.
        'require_verified_location' => (bool) env('LEARNING_REQUIRE_VERIFIED_LOCATION', true),
        'ip_range_cidr_mask' => 24,
        'min_samples_for_active' => 10,
        'min_success_rate' => 70,
        'retention_days' => (int) env('DETECTION_RETENTION_DAYS', 90),
        'fingerprint_retention_days' => (int) env('FINGERPRINT_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signal Weights (for fusion engine)
    |--------------------------------------------------------------------------
    | Higher weight = more influence on the final location prediction.
    */
    'signal_weights' => [
        'cloudflare' => 50,
        'fingerprint_history' => 40,
        // network_probe: browser-measured CF PoP routing — independent of IP GeoIP databases.
        // Weighted higher than ensemble because it measures actual network topology,
        // not a database lookup. Provides city-level when RTT ≤ 10ms, state-level otherwise.
        'network_probe' => 35,
        'language_inference' => 25,
        'ip_ensemble' => 20,
        'local_geoip' => 18,
        'reverse_dns' => 15,
        'network_signals' => 10,
        'font_detection' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits (requests per minute)
    |--------------------------------------------------------------------------
    */
    // Test IP override (for local/testing environments)
    'test_ip' => env('TEST_IP'),
    'default_country' => env('DETECTION_DEFAULT_COUNTRY', 'India'),

    'rate_limits' => [
        'free' => (int) env('API_RATE_LIMIT_PER_MINUTE', 100),
        'starter' => 500,
        'growth' => 2000,
        'admin' => 10000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache TTLs (seconds)
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'ip_geo_ttl' => 3600,       // 1 hour for IP geolocation
        'api_key_ttl' => 300,        // 5 minutes for API key validation
        'ensemble_ttl' => 3600,      // 1 hour for ensemble results
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance / SLO Targets
    |--------------------------------------------------------------------------
    */
    'slo' => [
        'detect_p95_ms' => (int) env('DETECT_SLO_P95_MS', 700),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile Carrier ASNs (India)
    |--------------------------------------------------------------------------
    */
    'mobile_asns' => [
        'AS55836',  // Reliance Jio
        'AS24560',  // Airtel
        'AS9829',   // BSNL
        'AS45609',  // Vodafone Idea
        'AS38266',  // Vodafone
        'AS17762',  // Tata Communications
        'AS18101',  // Reliance Communications
        'AS45820',  // Tata Teleservices
    ],
];
