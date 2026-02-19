<?php

namespace Tests\Unit;

use App\Services\VPNDetectionService;
use Tests\TestCase;

class VPNDetectionServiceTest extends TestCase
{
    private VPNDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VPNDetectionService();
    }

    public function test_detects_datacenter_asn_as_vpn(): void
    {
        $result = $this->service->detect('1.2.3.4', 'AS16509', 'ec2-1-2-3-4.compute.amazonaws.com');

        $this->assertTrue($result['is_vpn']);
        $this->assertContains('datacenter_asn', $result['indicators']);
        $this->assertContains('hosting_provider', $result['indicators']);
    }

    public function test_detects_vpn_keywords_in_hostname(): void
    {
        $result = $this->service->detect('1.2.3.4', null, 'vpn-exit-node.example.com');

        $this->assertTrue($result['is_vpn']);
        $this->assertContains('vpn_hostname', $result['indicators']);
    }

    public function test_detects_hosting_provider(): void
    {
        $result = $this->service->detect('1.2.3.4', null, 'server.digitalocean.com');

        $this->assertContains('hosting_provider', $result['indicators']);
    }

    public function test_legitimate_isp_not_flagged_as_vpn(): void
    {
        $result = $this->service->detect('103.50.100.5', 'AS55836', 'host.ahmedabad.gtpl.net.in');

        $this->assertFalse($result['is_vpn']);
        $this->assertEmpty($result['indicators']);
    }

    public function test_indian_mobile_carrier_not_flagged(): void
    {
        $result = $this->service->detect('49.36.100.5', 'AS55836', null);

        $this->assertFalse($result['is_vpn']);
    }

    public function test_vpn_score_calculation(): void
    {
        // Datacenter ASN alone = 40, below threshold
        $result = $this->service->detect('1.2.3.4', 'AS16509', null);
        $this->assertFalse($result['is_vpn']);
        $this->assertEquals(40, $result['vpn_score']);

        // Datacenter + hosting keywords = 40 + 25 = 65, above threshold
        $result = $this->service->detect('1.2.3.4', 'AS16509', 'server.amazonaws.com');
        $this->assertTrue($result['is_vpn']);
        $this->assertGreaterThanOrEqual(65, $result['vpn_score']);
    }

    public function test_no_data_returns_no_vpn(): void
    {
        $result = $this->service->detect('103.50.100.5', null, null);

        $this->assertFalse($result['is_vpn']);
        $this->assertEquals(0, $result['vpn_score']);
        $this->assertEmpty($result['indicators']);
    }
}
