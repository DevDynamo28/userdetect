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
        $this->service = new EnsembleIPService();
        Cache::flush();
    }

    public function test_all_sources_agree_gives_high_confidence(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([
                'city' => 'Ahmedabad', 'region' => 'Gujarat', 'country_name' => 'India',
                'country_code' => 'IN', 'postal' => '380001', 'asn' => 'AS55836', 'org' => 'GTPL',
            ]),
            'ip-api.com/*' => Http::response([
                'status' => 'success', 'city' => 'Ahmedabad', 'regionName' => 'Gujarat',
                'country' => 'India', 'countryCode' => 'IN', 'zip' => '380001',
                'as' => 'AS55836 GTPL', 'isp' => 'GTPL',
            ]),
            'geoplugin.net/*' => Http::response([
                'geoplugin_city' => 'Ahmedabad', 'geoplugin_region' => 'Gujarat',
                'geoplugin_countryName' => 'India', 'geoplugin_countryCode' => 'IN',
            ]),
            'ipwhois.app/*' => Http::response([
                'success' => true, 'city' => 'Ahmedabad', 'region' => 'Gujarat',
                'country' => 'India', 'country_code' => 'IN', 'postal' => '380001',
                'connection' => ['asn' => 'AS55836', 'isp' => 'GTPL'],
            ]),
        ]);

        $result = $this->service->lookup('103.50.100.5');

        $this->assertEquals('Ahmedabad', $result['city']);
        $this->assertEquals('Gujarat', $result['state']);
        $this->assertEquals(85, $result['confidence']);
        $this->assertEquals(4, $result['agreement_count']);
    }

    public function test_three_sources_agree_gives_medium_high_confidence(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([
                'city' => 'Ahmedabad', 'region' => 'Gujarat', 'country_name' => 'India',
                'asn' => 'AS55836', 'org' => 'GTPL',
            ]),
            'ip-api.com/*' => Http::response([
                'status' => 'success', 'city' => 'Ahmedabad', 'regionName' => 'Gujarat',
                'country' => 'India', 'as' => 'AS55836 GTPL', 'isp' => 'GTPL',
            ]),
            'geoplugin.net/*' => Http::response([
                'geoplugin_city' => 'Surat', 'geoplugin_region' => 'Gujarat',
                'geoplugin_countryName' => 'India',
            ]),
            'ipwhois.app/*' => Http::response([
                'success' => true, 'city' => 'Ahmedabad', 'region' => 'Gujarat',
                'country' => 'India', 'connection' => ['asn' => 'AS55836', 'isp' => 'GTPL'],
            ]),
        ]);

        $result = $this->service->lookup('103.50.100.5');

        $this->assertEquals('Ahmedabad', $result['city']);
        $this->assertEquals(75, $result['confidence']);
        $this->assertEquals(3, $result['agreement_count']);
    }

    public function test_no_agreement_gives_low_confidence(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([
                'city' => 'Ahmedabad', 'region' => 'Gujarat', 'country_name' => 'India',
                'asn' => 'AS55836', 'org' => 'GTPL',
            ]),
            'ip-api.com/*' => Http::response([
                'status' => 'success', 'city' => 'Mumbai', 'regionName' => 'Maharashtra',
                'country' => 'India', 'as' => 'AS55836 GTPL', 'isp' => 'GTPL',
            ]),
            'geoplugin.net/*' => Http::response([
                'geoplugin_city' => 'Delhi', 'geoplugin_region' => 'Delhi',
                'geoplugin_countryName' => 'India',
            ]),
            'ipwhois.app/*' => Http::response([
                'success' => true, 'city' => 'Chennai', 'region' => 'Tamil Nadu',
                'country' => 'India', 'connection' => ['asn' => 'AS55836', 'isp' => 'GTPL'],
            ]),
        ]);

        $result = $this->service->lookup('103.50.100.5');

        $this->assertEquals(50, $result['confidence']);
        $this->assertEquals(1, $result['agreement_count']);
    }

    public function test_handles_api_failures_gracefully(): void
    {
        Http::fake([
            'ipapi.co/*' => Http::response([
                'city' => 'Ahmedabad', 'region' => 'Gujarat', 'country_name' => 'India',
                'asn' => 'AS55836', 'org' => 'GTPL',
            ]),
            'ip-api.com/*' => Http::response(null, 500),
            'geoplugin.net/*' => Http::response(null, 500),
            'ipwhois.app/*' => Http::response(null, 500),
        ]);

        $result = $this->service->lookup('103.50.100.5');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('confidence', $result);
    }

    public function test_results_are_cached(): void
    {
        Http::fake([
            '*' => Http::response([
                'city' => 'Ahmedabad', 'region' => 'Gujarat', 'country_name' => 'India',
                'asn' => 'AS55836', 'org' => 'GTPL',
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
                'city' => 'Bengaluru', 'region' => 'Karnataka', 'country_name' => 'India',
                'asn' => 'AS55836', 'org' => 'ISP',
            ]),
            'ip-api.com/*' => Http::response([
                'status' => 'success', 'city' => 'Bangalore', 'regionName' => 'Karnataka',
                'country' => 'India', 'as' => 'AS55836', 'isp' => 'ISP',
            ]),
            'geoplugin.net/*' => Http::response([
                'geoplugin_city' => 'Bangalore', 'geoplugin_region' => 'Karnataka',
                'geoplugin_countryName' => 'India',
            ]),
            'ipwhois.app/*' => Http::response([
                'success' => true, 'city' => 'Bengaluru', 'region' => 'Karnataka',
                'country' => 'India', 'connection' => ['asn' => 'AS55836', 'isp' => 'ISP'],
            ]),
        ]);

        $result = $this->service->lookup('49.36.100.5');

        // Both Bengaluru and Bangalore should normalize to Bangalore
        $this->assertEquals('Bangalore', $result['city']);
        $this->assertEquals(85, $result['confidence']);
    }
}
