<?php

namespace Tests\Unit;

use App\Services\Presence\GeoIpService;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class GeoIpServiceTest extends TestCase
{
    private GeoIpService $geo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geo = new GeoIpService;
    }

    public function test_local_and_private_ips_are_local(): void
    {
        $this->assertTrue($this->geo->isLocalIp('127.0.0.1'));
        $this->assertTrue($this->geo->isLocalIp('::1'));
        $this->assertTrue($this->geo->isLocalIp('192.168.0.10'));
        $this->assertTrue($this->geo->isLocalIp('10.0.0.2'));
        $this->assertTrue($this->geo->isLocalIp(null));
    }

    public function test_public_ip_is_not_local(): void
    {
        $this->assertFalse($this->geo->isLocalIp('8.8.8.8'));
    }

    public function test_local_ip_returns_local_country_without_lookup(): void
    {
        $this->assertSame('local', $this->geo->lookupIp('127.0.0.1')['country']);
        $this->assertSame('local', $this->geo->lookupIp('192.168.1.5')['country']);
    }

    public function test_client_supplied_proxy_headers_cannot_change_resolved_ip(): void
    {
        $request = Request::create('/api/ping', 'GET', [], [], [], [
            'REMOTE_ADDR' => '8.8.8.8',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            'HTTP_X_REAL_IP' => '5.6.7.8',
            'HTTP_CF_CONNECTING_IP' => '9.10.11.12',
        ]);

        $this->assertSame('8.8.8.8', $this->geo->resolveClientIp($request));
    }
}
