<?php

namespace App\Jobs;

use App\Data\PushNotificationData;
use App\Services\Account\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverWebPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public int $expiresAt;

    /** @var array<string, mixed> */
    public array $payload;

    public function __construct(public int $subscriptionId, PushNotificationData $payload)
    {
        $this->payload = $payload->toArray();
        $this->expiresAt = now()->addSeconds(($payload->kind ?? '') === 'call' ? 60 : 3600)->timestamp;
        $connection = config('services.webpush.queue_connection') ?: config('queue.default');
        $this->onConnection(in_array($connection, ['sync', 'null'], true) ? 'database' : $connection);
        $this->onQueue('background');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(PushNotificationService $push): void
    {
        if (now()->timestamp < $this->expiresAt) {
            $push->deliver($this->subscriptionId, PushNotificationData::fromArray($this->payload));
        }
    }
}
