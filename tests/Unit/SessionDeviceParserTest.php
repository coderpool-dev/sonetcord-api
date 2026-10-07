<?php

namespace Tests\Unit;

use App\Services\Account\SessionDeviceParser;
use PHPUnit\Framework\TestCase;

class SessionDeviceParserTest extends TestCase
{
    private SessionDeviceParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SessionDeviceParser;
    }

    public function test_detects_goydacord_desktop(): void
    {
        $userAgent = 'SonetCord/1.0.60 (Windows) Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/138.0.0.0 Electron/37.0.0 Safari/537.36';
        $device = $this->parser->describe($userAgent);

        $this->assertSame('SonetCord 1.0.60 · Windows', $device['device']);
        $this->assertSame('desktop', $device['device_type']);
        $this->assertSame('Windows', $device['os']);
        $this->assertSame('SonetCord 1.0.60', $device['client']);
    }

    public function test_detects_sonetcord_desktop(): void
    {
        $userAgent = 'SonetCord/1.1.3 (win32) Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/138.0.0.0 Safari/537.36';
        $device = $this->parser->describe($userAgent);

        $this->assertSame('SonetCord 1.1.3 · Windows', $device['device']);
        $this->assertSame('desktop', $device['device_type']);
        $this->assertSame('desktop', $this->parser->platformKind($userAgent));
    }

    public function test_detects_electron_before_chrome(): void
    {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.7204.251 Electron/37.2.6 Safari/537.36';
        $device = $this->parser->describe($userAgent);

        $this->assertSame('SonetCord · Windows', $device['device']);
        $this->assertSame('SonetCord', $device['client']);
    }

    public function test_detects_iphone_safari(): void
    {
        $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        $device = $this->parser->describe($userAgent);

        $this->assertSame('Safari · iPhone', $device['device']);
        $this->assertSame('mobile', $device['device_type']);
    }

    public function test_empty_user_agent(): void
    {
        $device = $this->parser->describe(null);

        $this->assertSame('Неизвестное устройство', $device['device']);
        $this->assertSame('unknown', $device['device_type']);
    }
}
