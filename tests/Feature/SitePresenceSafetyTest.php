<?php

namespace Tests\Feature;

use App\Jobs\ResolveSitePresenceCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SitePresenceSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_heartbeat_queues_only_one_geoip_lookup(): void
    {
        Queue::fake();
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8']);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/presence/ping', ['session_key' => 'session-12345678'])->assertOk();
        }

        Queue::assertPushed(ResolveSitePresenceCountry::class, 1);
    }

    public function test_geoip_dispatch_is_capped_globally_without_rejecting_presence(): void
    {
        Queue::fake();
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.4.4']);

        for ($i = 0; $i < 31; $i++) {
            $this->postJson('/api/presence/ping', ['session_key' => "visitor-session-{$i}"])->assertOk();
        }

        Queue::assertPushed(ResolveSitePresenceCountry::class, 30);
    }
}
