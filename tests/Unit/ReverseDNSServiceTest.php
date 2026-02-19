<?php

namespace Tests\Unit;

use App\Services\ReverseDNSService;
use Tests\TestCase;

class ReverseDNSServiceTest extends TestCase
{
    private ReverseDNSService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReverseDNSService();
    }

    public function test_extracts_city_from_gtpl_hostname(): void
    {
        $result = $this->service->extractCity('host123.ahmedabad.gtpl.net.in');

        $this->assertNotNull($result);
        $this->assertEquals('Ahmedabad', $result['city']);
        $this->assertEquals('Gujarat', $result['state']);
        $this->assertEquals(88, $result['confidence']);
        $this->assertEquals('reverse_dns', $result['method']);
    }

    public function test_extracts_city_from_airtel_hostname(): void
    {
        $result = $this->service->extractCity('abts-mumbai-dynamic.airtelbroadband.in');

        $this->assertNotNull($result);
        $this->assertEquals('Mumbai', $result['city']);
        $this->assertEquals('Maharashtra', $result['state']);
    }

    public function test_extracts_city_from_hathway_hostname(): void
    {
        $result = $this->service->extractCity('host.pune.hathway.com');

        $this->assertNotNull($result);
        $this->assertEquals('Pune', $result['city']);
    }

    public function test_extracts_city_from_bsnl_hostname(): void
    {
        $result = $this->service->extractCity('host123.delhi.bsnl.in');

        $this->assertNotNull($result);
        $this->assertEquals('Delhi', $result['city']);
    }

    public function test_returns_null_for_unknown_hostname(): void
    {
        $result = $this->service->extractCity('unknown.random.hostname.example.com');

        $this->assertNull($result);
    }

    public function test_returns_null_for_empty_hostname(): void
    {
        $this->assertNull($this->service->extractCity(null));
        $this->assertNull($this->service->extractCity(''));
        $this->assertNull($this->service->extractCity('localhost'));
    }

    public function test_fuzzy_match_abbreviations(): void
    {
        $this->assertEquals('Mumbai', $this->service->fuzzyMatchCity('mum'));
        $this->assertEquals('Mumbai', $this->service->fuzzyMatchCity('bom'));
        $this->assertEquals('Ahmedabad', $this->service->fuzzyMatchCity('ahm'));
        $this->assertEquals('Ahmedabad', $this->service->fuzzyMatchCity('amd'));
        $this->assertEquals('Bangalore', $this->service->fuzzyMatchCity('blr'));
        $this->assertEquals('Delhi', $this->service->fuzzyMatchCity('del'));
        $this->assertEquals('Surat', $this->service->fuzzyMatchCity('surat'));
        $this->assertEquals('Vadodara', $this->service->fuzzyMatchCity('baroda'));
    }

    public function test_fuzzy_match_case_insensitive(): void
    {
        $this->assertEquals('Mumbai', $this->service->fuzzyMatchCity('MUM'));
        $this->assertEquals('Ahmedabad', $this->service->fuzzyMatchCity('Ahmedabad'));
        $this->assertEquals('Delhi', $this->service->fuzzyMatchCity('DELHI'));
    }

    public function test_fuzzy_match_returns_null_for_unknown(): void
    {
        $this->assertNull($this->service->fuzzyMatchCity('xy'));
        $this->assertNull($this->service->fuzzyMatchCity('zz'));
    }

    public function test_get_state_for_city(): void
    {
        $this->assertEquals('Gujarat', $this->service->getStateForCity('Ahmedabad'));
        $this->assertEquals('Maharashtra', $this->service->getStateForCity('Mumbai'));
        $this->assertEquals('Karnataka', $this->service->getStateForCity('Bangalore'));
        $this->assertNull($this->service->getStateForCity('UnknownCity'));
    }
}
