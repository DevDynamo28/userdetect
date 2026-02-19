<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Detection Methods (Priority Order)
    |--------------------------------------------------------------------------
    */
    'methods' => [
        'reverse_dns' => [
            'enabled' => true,
            'confidence' => 88,
            'priority' => 1,
        ],
        'ensemble_ip' => [
            'enabled' => true,
            'confidence_base' => 75,
            'priority' => 2,
            'timeout' => 3, // seconds per API
            'geo_cluster_radius_km' => 50, // cities within this radius are treated as same location
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
        'ip_range_cidr_mask' => 24,
        'min_samples_for_active' => 10,
        'min_success_rate' => 70,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits (requests per minute)
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'free' => (int) env('API_RATE_LIMIT_PER_MINUTE', 100),
        'starter' => 500,
        'growth' => 2000,
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
