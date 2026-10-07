<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebPush;
use App\Models\Account\PushSubscription;
use App\Services\Account\PushNotificationService;
use App\Services\Http\PublicHttpDestination;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\MessageSentReport;
use RuntimeException;
use Tests\Concerns\InteractsWithCalls;
use Tests\TestCase;

class WebPushDeliveryTest extends TestCase
{
    use InteractsWithCalls, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.webpush.public_key' => 'test', 'services.webpush.private_key' => 'test']);
        $this->app->instance(PublicHttpDestination::class, new class extends PublicHttpDestination
        {
            protected function resolveHostname(string $host): array
            {
                return ['93.184.215.14'];
            }
        });
    }

    private function subscription(): PushSubscription
    {
        $user = $this->makeUser();
        $push = app(PushNotificationService::class);
        $push->subscribe($user->id, 'https://fcm.googleapis.com/fcm/send/test', 'pk', 'au', 'mobile');

        return PushSubscription::query()->firstOrFail();
    }

    private function sender(int $status): PushNotificationService
    {
        return new class($status) extends PushNotificationService
        {
            public function __construct(private int $status) {}

            protected function sendNotification(PushSubscription $subscription, array $payload, array $curlOptions): MessageSentReport
            {
                return new MessageSentReport(new Request('POST', $subscription->endpoint), new Response($this->status), $this->status < 400);
            }
        };
    }

    public function test_queues_each_subscription_on_the_existing_background_worker(): void
    {
        Queue::fake();
        $sub = $this->subscription();
        app(PushNotificationService::class)->sendToUsers([$sub->user_id, $sub->user_id], ['kind' => 'message']);
        Queue::assertPushed(DeliverWebPush::class, fn (DeliverWebPush $job) => $job->subscriptionId === $sub->id
            && $job->queue === 'background' && $job->connection === 'database' && $job->afterCommit === true);
        Queue::assertPushed(DeliverWebPush::class, 1);
    }

    public function test_expired_subscription_is_deleted(): void
    {
        $sub = $this->subscription();
        $this->sender(410)->deliver($sub->id, []);
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $sub->id]);
    }

    public function test_temporary_failure_is_rethrown_for_queue_retry(): void
    {
        $sub = $this->subscription();
        $this->expectException(RuntimeException::class);
        $this->sender(503)->deliver($sub->id, []);
    }

    public function test_unsafe_legacy_subscription_is_removed_before_transport(): void
    {
        $sub = $this->subscription();
        $sub->update(['endpoint' => 'https://127.0.0.1/push']);
        $sender = $this->mock(PushNotificationService::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $sender->shouldNotReceive('sendNotification');
        $sender->deliver($sub->id, []);
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $sub->id]);
    }

    public function test_old_call_notification_is_not_delivered(): void
    {
        $sub = $this->subscription();
        $job = new DeliverWebPush($sub->id, ['kind' => 'call']);
        $this->travel(61)->seconds();
        $sender = $this->mock(PushNotificationService::class);
        $sender->shouldNotReceive('deliver');
        $job->handle($sender);
    }
}
