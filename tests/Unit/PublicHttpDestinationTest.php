<?php

namespace Tests\Unit;

use App\Services\Http\BoundedResponseBody;
use App\Services\Http\PublicHttpDestination;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PublicHttpDestinationTest extends TestCase
{
    public function test_pins_checked_address_without_changing_the_hostname(): void
    {
        $destination = new class extends PublicHttpDestination
        {
            protected function resolveHostname(string $host): array
            {
                return ['93.184.215.14'];
            }
        };
        $options = $destination->curlOptions('https://example.com/page');
        $this->assertSame(['example.com:443:93.184.215.14'], $options[CURLOPT_RESOLVE]);
        $this->assertSame('', $options[CURLOPT_PROXY]);
    }

    public function test_rejects_private_and_special_addresses_and_unsafe_urls(): void
    {
        $destination = new PublicHttpDestination;
        foreach (['https://127.0.0.1/', 'https://10.0.0.1/', 'http://100.64.0.1/',
            'http://224.0.0.1/', 'https://[::1]/', 'https://[::ffff:127.0.0.1]/',
            'https://[fc00::1]/', 'https://[2002:7f00:1::]/', 'http://2130706433/',
            'https://user:pass@example.com/', 'https://example.com:8443/', 'file:///etc/passwd'] as $url) {
            try {
                $destination->curlOptions($url);
                $this->fail('Accepted unsafe URL: '.$url);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_rejects_hostname_with_mixed_public_and_private_dns_answers(): void
    {
        $destination = new class extends PublicHttpDestination
        {
            protected function resolveHostname(string $host): array
            {
                return ['93.184.215.14', '127.0.0.1'];
            }
        };
        $this->expectException(InvalidArgumentException::class);
        $destination->curlOptions('https://example.com/');
    }

    public function test_body_limit_is_enforced_during_writes_before_buffering_excess_bytes(): void
    {
        $body = BoundedResponseBody::create(8);
        $body->write('1234');
        $body->write('5678');
        try {
            $body->write(str_repeat('x', 1000));
            $this->fail('Oversized response was accepted');
        } catch (RuntimeException) {
            $this->assertSame(8, $body->getSize());
            $this->assertSame('12345678', (string) $body);
        }
    }
}
