<?php

namespace Tests\Unit;

use App\Services\EnsembleIPService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnsembleIPServiceTest extends TestCase
{
    private EnsembleIPService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('detection.methods.ensemble_ip.allow_insecure_sources', true);
        config()->set('detection.methods.ensemble_ip.enabled_sources', [
            'ipapi',
            'ip-api',
            'geoplugin',
            'ipwhois',
            'ipwho',
            'freeipapi',
        ]);
        $this->service = new EnsembleIPService();
        Cache::flush();
    }

    public function test_all_sources_agree_gives_high_confidence(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([
                'city' => 'Ahmedabad',
                'region' => 'Gujarat',
                'country_name' => 'India',
                'country_code' => 'IN',
                'postal' => '380001',
                'asn' => 'AS55836',
                'org' => 'GTPL',
                'latitude' => 23.0225,
                'longitude' => 72.5714,
            ]),
            'ip-api.com/*' => Http::response([
                'status' => 'success',
                'city' => 'Ahmedabad',
                'regionName' => 'Gujarat',
                'country' => 'India',
                'countryCode' => 'IN',
                'zip' => '380001',
                'lat' => 23.0225,
                'lon' => 72.5714,
                'as' => 'AS55836 GTPL',
                'isp' => 'GTPL',
            ]),
            'geoplugin.net/*' => Http::response([
                'geoplugin_city' => 'Ahmedabad',
                'geoplugin_region' => 'Gujarat',
                'geoplugin_countryName' => 'India',
                'geoplugin_countryCode' => 'IN',
                'geoplugin_latitude' => '23.0225',
                'geoplugin_longitude' => '72.5714',
            ]),
            'ipwhois.app/*' => Http::response([
                'success' => true,
                'city' => 'Ahmedabad',
                'region' => 'Gujarat',
                'country' => 'India',
                'country_code' => 'IN',
                'postal' => '380001',
                'latitude' => 23.0225,
                'longitude' => 72.5714,
                'connection' => ['asn' => 'AS55836', 'isp' => 'GTPL'],
            ]),
            'ipwho.is/*' => Http::response([
                'success' => true,
                'city' => 'Ahmedabad',
                'region' => 'Gujarat',
                'country' => 'India',
                'country_code' => 'IN',
                'postal' => '380001',
                'latitude' => 23.0225,
                'longitude' => 72.5714,
                'connection' => ['asn' => 'AS55836', 'isp' => 'GTPL', 'org' => 'GTPL'],
            ]),
            'freeipapi.com/*' => Http::response([
                'cityName' => 'Ahmedabad',
                'regionName' => 'Gujarat',
                'countryName' => 'India',
                'countryCode' => 'IN',
                'zipCode' => '380001',
                'latitude' => 23.0225,
                'longitude' => 72.5714,
            ]),
        ]);

        $result = $this->service->lookup('103.50.100.5');

        $this->assertEquals('Ahmedabad', $result['city']);
        $this->assertEquals('Gujarat', $result['state']);
        $this->assertGreaterThanOrEqual(90, $result['confidence']);
        $this->assertEquals(6, $result['agreement_count']);
        $this->assertNotNull($result['latitude']);
        $this->assertNotNull($result['longitude']);
    }

    public function test_most_sources_agree_gives_high_confidence(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([
                'city' => 'Ahmedabad',
                'region' => 'Gujarat',
                'country_name' => 'India',
                'asn' => 'AS55836',
                'org' => 'GTPL',
                'latitude' => 23.0225,
                'longitude' => 72.5714,
            ]),
            'ip-api.com/*' => Http::response([
                'status' => 'success',
                'city' => 'Ahmedabad',
                'regionName' => 'Gujarat',
                'country' => 'India',
                'lat' => 23.0225,
                'lon' => 72.5714,
                'as' => 'AS55836 GTPL',
                'isp' => 'GTPL',
            ]),
            'geoplugin.net/*' => Http::response([
                'geoplugin_city' => 'Surat',
                'geoplugin_region' => 'Gujarat',
                'geoplugin_countryName' => 'India',
                'geoplugin_latitude' => '21.1702',
                'geoplugin_longitude' => '72.8311',
            ]),
            'ipwhois.app/*' => Http::response([
                'success' => true,
                'city' => 'Ahmedabad',
                'region' => 'Gujarat',
                'country' => 'India',
                'latitude' => 23.0225,
                'longitude' => 72.5714,
                'connection' => ['asn' => 'AS55836', 'isp' => 'GTPL'],
            ]),
            'ipwho.is/*' => Http::response([
                'success' => true,
                'city' => 'Ahmedabad',
                'region' => 'Gujarat',
                'country' => 'India',
                'latitude' => 23.0225,
                'longitude' => 72.5714,
                'connection' => ['asn' => 'AS55836', 'isp' => 'GTPL', 'org' => 'GTPL'],
            ]),
            'freeipapi.com/*' => Http::response([
                'cityName' => 'Ahmedabad',
                'regionName' => 'Gujarat',
                'countryName' => 'India',
                'latitude' => 23.0225,
                'longitude' => 72.5714,
            ]),
        ]);

        $result = $this->service->lookup('103.50.100.5');

        $this->assertEquals('Ahmedabad', $result['city']);
        $this->assertGreaterThanOrEqual(80, $result['confidence']);
        $this->assertGreaterThanOrEqual(5, $result['agreement_count']);
    }

    public function test_geo_clustering_groups_nearby_cities(): void
    {
        // Ahmedabad (23.02, 72.57) and Gandhinagar (23.22, 72.68) are ~25km apart
        // They should be clustered together
        Http::fake([
            'ipapi.co/*' => Http::response([
                'city' => 'Ahmedabad',
                'region' => 'Gujarat',
                'country_name' => 'India',
                'latitude' => 23.0225,
                'longitude' => 72.5714,
            ]),
            'ip-api.com/*' => Http::response([
                'status' => 'success',
                'city' => 'Gandhinagar',
                'regionName' => 'Gujarat',
                'country' => 'India',
                'lat' => 23.2156,
                'lon' => 72.6369,
            ]),
            'geoplugin.net/*' => Http::response([
                'geoplugin_city' => 'Ahmedabad',
                'geoplugin_region' => 'Gujarat',
                'geoplugin_countryName' => 'India',
                'geoplugin_latitude' => '23.0225',
                'geoplugin_longitude' => '72.5714',
            ]),
            'ipwhois.app/*' => Http::response([
                'success' => true,
                'city' => 'Gandhinagar',
                'region' => 'Gujarat',
                'country' => 'India',
                'latitude' => 23.2156,
                'longitude' => 72.6369,
                'connection' => ['asn' => 'AS55836', 'isp' => 'GTPL'],
            ]),
            'ipwho.is/*' => Http::response([
                'success' => true,
                'city' => 'Ahmedabad',
                'region' => 'Gujarat',
                'country' => 'India',
                'latitude' => 23.0225,
                'longitude' => 72.5714,
                'connection' => ['asn' => 'AS55836', 'isp' => 'GTPL', 'org' => 'GTPL'],
            ]),
            'freeipapi.com/*' => Http::response([
                'cityName' => 'Ahmedabad',
                'regionName' => 'Gujarat',
                'countryName' => 'India',
                'latitude' => 23.0225,
                'longitude' => 72.5714,
            ]),
        ]);

        $result = $this->service->lookup('103.50.100.5');

        // All 6 sources should cluster together since Ahmedabad & Gandhinagar are within 50km
        $this->assertGreaterThanOrEqual(6, $result['agreement_count']);
        $this->assertEquals('Gujarat', $result['state']);
        $this->assertGreaterThanOrEqual(90, $result['confidence']);
    }

    public function test_no_agreement_gives_low_confidence(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([
                'city' => 'Ahmedabad',
                'region' => 'Gujarat',
                'country_name' => 'India',
                'latitude' => 23.0225,
                'longitude' => 72.5714,
            ]),
            'ip-api.com/*' => Http::response([
                'status' => 'success',
                'city' => 'Mumbai',
                'regionName' => 'Maharashtra',
                'country' => 'India',
                'lat' => 19.0760,
                'lon' => 72.8777,
            ]),
            'geoplugin.net/*' => Http::response([
                'geoplugin_city' => 'Delhi',
                'geoplugin_region' => 'Delhi',
                'geoplugin_countryName' => 'India',
                'geoplugin_latitude' => '28.6139',
                'geoplugin_longitude' => '77.2090',
            ]),
            'ipwhois.app/*' => Http::response([
                'success' => true,
                'city' => 'Chennai',
                'region' => 'Tamil Nadu',
                'country' => 'India',
                'latitude' => 13.0827,
                'longitude' => 80.2707,
                'connection' => ['asn' => 'AS55836', 'isp' => 'GTPL'],
            ]),
            'ipwho.is/*' => Http::response([
                'success' => true,
                'city' => 'Kolkata',
                'region' => 'West Bengal',
                'country' => 'India',
                'latitude' => 22.5726,
                'longitude' => 88.3639,
                'connection' => ['asn' => 'AS55836', 'isp' => 'GTPL', 'org' => 'GTPL'],
            ]),
            'freeipapi.com/*' => Http::response([
                'cityName' => 'Bangalore',
                'regionName' => 'Karnataka',
                'countryName' => 'India',
                'latitude' => 12.9716,
                'longitude' => 77.5946,
            ]),
        ]);

        $result = $this->service->lookup('103.50.100.5');

        // All cities are far apart — no clustering possible
        $this->assertLessThanOrEqual(55, $result['confidence']);
        $this->assertEquals(1, $result['agreement_count']);
    }

    public function test_handles_api_failures_gracefully(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([
                'city' => 'Ahmedabad',
                'region' => 'Gujarat',
                'country_name' => 'India',
                'latitude' => 23.0225,
                'longitude' => 72.5714,
            ]),
            'ip-api.com/*' => Http::response(null, 500),
            'geoplugin.net/*' => Http::response(null, 500),
            'ipwhois.app/*' => Http::response(null, 500),
            'ipwho.is/*' => Http::response(null, 500),
            'freeipapi.com/*' => Http::response(null, 500),
        ]);

        $result = $this->service->lookup('103.50.100.5');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('latitude', $result);
        $this->assertArrayHasKey('longitude', $result);
    }

    public function test_results_are_cached(): void
    {
        Http::fake([
            '*' => Http::response([
                'city' => 'Ahmedabad',
                'region' => 'Gujarat',
                'country_name' => 'India',
                'latitude' => 23.0225,
                'longitude' => 72.5714,
                'asn' => 'AS55836',
                'org' => 'GTPL',
            ]),
        ]);

        // First call
        $this->service->lookup('103.50.100.5');

        // Clear fake to verify cache is used
        Http::fake([
            '*' => Http::response(null, 500),
        ]);

        // Second call should use cache
        $result = $this->service->lookup('103.50.100.5');
        $this->assertNotNull($result['city']);
    }

    public function test_normalizes_city_names(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([
                'city' => 'Bengaluru',
                'region' => 'Karnataka',
                'country_name' => 'India',
                'latitude' => 12.9716,
                'longitude' => 77.5946,
            ]),
            'ip-api.com/*' => Http::response([
                'status' => 'success',
                'city' => 'Bangalore',
                'regionName' => 'Karnataka',
                'country' => 'India',
                'lat' => 12.9716,
                'lon' => 77.5946,
            ]),
            'geoplugin.net/*' => Http::response([
                'geoplugin_city' => 'Bangalore',
                'geoplugin_region' => 'Karnataka',
                'geoplugin_countryName' => 'India',
                'geoplugin_latitude' => '12.9716',
                'geoplugin_longitude' => '77.5946',
            ]),
            'ipwhois.app/*' => Http::response([
                'success' => true,
                'city' => 'Bengaluru',
                'region' => 'Karnataka',
                'country' => 'India',
                'latitude' => 12.9716,
                'longitude' => 77.5946,
                'connection' => ['asn' => 'AS55836', 'isp' => 'ISP'],
            ]),
            'ipwho.is/*' => Http::response([
                'success' => true,
                'city' => 'Bangalore',
                'region' => 'Karnataka',
                'country' => 'India',
                'latitude' => 12.9716,
                'longitude' => 77.5946,
                'connection' => ['asn' => 'AS55836', 'isp' => 'ISP', 'org' => 'ISP'],
            ]),
            'freeipapi.com/*' => Http::response([
                'cityName' => 'Bengaluru',
                'regionName' => 'Karnataka',
                'countryName' => 'India',
                'latitude' => 12.9716,
                'longitude' => 77.5946,
            ]),
        ]);

        $result = $this->service->lookup('49.36.100.5');

        // Both Bengaluru and Bangalore should normalize to Bangalore
        $this->assertEquals('Bangalore', $result['city']);
        $this->assertGreaterThanOrEqual(90, $result['confidence']);
    }

    public function test_weighted_consensus_prefers_reliable_sources(): void
    {
        // ip-api (weight 1.5) and ipwho (weight 1.3) say Mumbai
        // Others say different cities — but the high-weight sources should win
        Http::fake([
            'ipapi.co/*' => Http::response([
                'city' => 'Pune',
                'region' => 'Maharashtra',
                'country_name' => 'India',
                'latitude' => 18.5204,
                'longitude' => 73.8567,
            ]),
            'ip-api.com/*' => Http::response([
                'status' => 'success',
                'city' => 'Mumbai',
                'regionName' => 'Maharashtra',
                'country' => 'India',
                'lat' => 19.0760,
                'lon' => 72.8777,
            ]),
            'geoplugin.net/*' => Http::response([
                'geoplugin_city' => 'Pune',
                'geoplugin_region' => 'Maharashtra',
                'geoplugin_countryName' => 'India',
                'geoplugin_latitude' => '18.5204',
                'geoplugin_longitude' => '73.8567',
            ]),
            'ipwhois.app/*' => Http::response([
                'success' => true,
                'city' => 'Mumbai',
                'region' => 'Maharashtra',
                'country' => 'India',
                'latitude' => 19.0760,
                'longitude' => 72.8777,
                'connection' => ['asn' => 'AS55836', 'isp' => 'ISP'],
            ]),
            'ipwho.is/*' => Http::response([
                'success' => true,
                'city' => 'Mumbai',
                'region' => 'Maharashtra',
                'country' => 'India',
                'latitude' => 19.0760,
                'longitude' => 72.8777,
                'connection' => ['asn' => 'AS55836', 'isp' => 'ISP', 'org' => 'ISP'],
            ]),
            'freeipapi.com/*' => Http::response([
                'cityName' => 'Pune',
                'regionName' => 'Maharashtra',
                'countryName' => 'India',
                'latitude' => 18.5204,
                'longitude' => 73.8567,
            ]),
        ]);

        $result = $this->service->lookup('103.50.100.5');

        // Mumbai should have higher weight despite equal count, because ip-api(1.5) + ipwho(1.3) + ipwhois(1.0) = 3.8
        // vs Pune: ipapi(1.0) + geoplugin(0.6) + freeipapi(0.8) = 2.4
        // But Mumbai and Pune are ~150km apart — they won't cluster together
        // So Mumbai cluster (3 sources, weight 3.8) beats Pune cluster (3 sources, weight 2.4)
        $this->assertEquals('Mumbai', $result['city']);
    }

    public function test_returns_lat_lng_in_result(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([
                'city' => 'Delhi',
                'region' => 'Delhi',
                'country_name' => 'India',
                'latitude' => 28.6139,
                'longitude' => 77.2090,
            ]),
            'ip-api.com/*' => Http::response([
                'status' => 'success',
                'city' => 'Delhi',
                'regionName' => 'Delhi',
                'country' => 'India',
                'lat' => 28.6139,
                'lon' => 77.2090,
            ]),
            'geoplugin.net/*' => Http::response([
                'geoplugin_city' => 'Delhi',
                'geoplugin_region' => 'Delhi',
                'geoplugin_countryName' => 'India',
                'geoplugin_latitude' => '28.6139',
                'geoplugin_longitude' => '77.2090',
            ]),
            'ipwhois.app/*' => Http::response([
                'success' => true,
                'city' => 'Delhi',
                'region' => 'Delhi',
                'country' => 'India',
                'latitude' => 28.6139,
                'longitude' => 77.2090,
                'connection' => ['asn' => 'AS24560', 'isp' => 'Airtel'],
            ]),
            'ipwho.is/*' => Http::response([
                'success' => true,
                'city' => 'Delhi',
                'region' => 'Delhi',
                'country' => 'India',
                'latitude' => 28.6139,
                'longitude' => 77.2090,
                'connection' => ['asn' => 'AS24560', 'isp' => 'Airtel', 'org' => 'Airtel'],
            ]),
            'freeipapi.com/*' => Http::response([
                'cityName' => 'Delhi',
                'regionName' => 'Delhi',
                'countryName' => 'India',
                'latitude' => 28.6139,
                'longitude' => 77.2090,
            ]),
        ]);

        $result = $this->service->lookup('122.161.68.1');

        $this->assertEquals('Delhi', $result['city']);
        $this->assertNotNull($result['latitude']);
        $this->assertNotNull($result['longitude']);
        $this->assertEqualsWithDelta(28.6139, $result['latitude'], 0.1);
        $this->assertEqualsWithDelta(77.2090, $result['longitude'], 0.1);
    }
}
