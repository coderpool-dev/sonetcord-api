<?php

namespace Tests\Feature;

use App\Data\PushNotificationData;
use App\Jobs\DeliverWebPush;
use App\Jobs\ResolveSitePresenceCountry;
use Tests\TestCase;

class BackgroundQueueRoutingTest extends TestCase
{
    public function test_background_jobs_use_redis_when_configured(): void
    {
        config(['queue.default' => 'redis', 'services.webpush.queue_connection' => null]);
        $geo = new ResolveSitePresenceCountry('test', '127.0.0.1');
        $push = new DeliverWebPush(0, PushNotificationData::fromArray(['title' => 'Test', 'body' => 'Test', 'url' => '/', 'tag' => 'test']));
        $this->assertSame('redis', $geo->connection);
        $this->assertSame('redis', $push->connection);
        $this->assertSame('background', $geo->queue);
        $this->assertSame('background', $push->queue);
        $this->assertTrue($push->afterCommit);
    }

    public function test_sync_defaults_do_not_run_external_work_in_http_requests(): void
    {
        config(['queue.default' => 'sync', 'services.webpush.queue_connection' => null]);
        $this->assertSame('database', (new ResolveSitePresenceCountry('test', '127.0.0.1'))->connection);
    }
}
