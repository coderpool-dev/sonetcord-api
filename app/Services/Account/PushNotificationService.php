<?php

namespace App\Services\Account;

use App\Jobs\DeliverWebPush;
use App\Models\Account\PushSubscription;
use App\Services\Http\PublicHttpDestination;
use App\Support\PushEndpoint;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Web Push (VAPID): личные сообщения, упоминания на сервере, входящие звонки — даже когда вкладка
 * закрыта. Service Worker на фронте сам не показывает уведомление, если приложение открыто и в
 * фокусе (там всё видно и так). Отправка — отдельной задачей после коммита.
 * Протухшие подписки (404/410) удаляются, временные ошибки повторяет очередь.
 */
class PushNotificationService
{
    private const DESKTOP_APP_TTL_SECONDS = 60;

    public function isConfigured(): bool
    {
        return (bool) config('services.webpush.public_key') && (bool) config('services.webpush.private_key');
    }

    public function subscribe(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent): void
    {
        if (! PushEndpoint::isAllowed($endpoint)) {
            throw ValidationException::withMessages(['endpoint' => ['Недопустимый адрес push-сервиса']]);
        }
        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            [
                'user_id' => $userId,
                'endpoint' => $endpoint,
                'p256dh' => $p256dh,
                'auth' => $auth,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
            ],
        );
    }

    public function unsubscribe(int $userId, string $endpoint): void
    {
        PushSubscription::query()
            ->where('user_id', $userId)
            ->where('endpoint_hash', hash('sha256', $endpoint))
            ->delete();
    }

    /**
     * @param  int[]  $userIds
     * @param  array{title: string, body: string, url: string, tag: string, icon?: string|null, kind?: string}  $payload
     */
    public function sendToUsers(array $userIds, array $payload): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === [] || ! $this->isConfigured()) {
            return;
        }

        foreach ($this->subscriptionsFor($userIds) as $subscription) {
            DeliverWebPush::dispatch((int) $subscription->id, $payload);
        }
    }

    /** Приложение для Windows пингует /auth/online раз в 15 с даже из трея: без пинга минуту — закрыто. */
    public function markDesktopAppActive(int $userId): void
    {
        Cache::put(self::desktopAppKey($userId), true, self::DESKTOP_APP_TTL_SECONDS);
    }

    /**
     * Подписки, на которые шлём. Пока у пользователя запущено приложение для Windows, оно само показывает
     * уведомление, и клик по нему открывает приложение. Пуш в браузер на компьютере его дублировал бы,
     * а «Принять» в браузерном уведомлении открывало бы сайт вместо приложения. Телефонам шлём всегда.
     *
     * @param  int[]  $userIds
     * @return Collection<int, PushSubscription>
     */
    public function subscriptionsFor(array $userIds): Collection
    {
        $withDesktopApp = array_values(array_filter($userIds, fn (int $id) => Cache::has(self::desktopAppKey($id))));
        $devices = app(SessionDeviceParser::class);

        return PushSubscription::query()
            ->whereIn('user_id', $userIds)
            ->get()
            ->reject(fn (PushSubscription $sub) => in_array((int) $sub->user_id, $withDesktopApp, true)
                && $devices->platformKind($sub->user_agent) === 'web')
            ->values();
    }

    private static function desktopAppKey(int $userId): string
    {
        return "push:desktop-app:{$userId}";
    }

    public function deliver(int $subscriptionId, array $payload): void
    {
        $subscription = PushSubscription::query()->find($subscriptionId);
        if (! $subscription || ! $this->isConfigured()) {
            return;
        }
        // Validate again: old rows and DNS changes must not bypass the destination policy.
        if (! PushEndpoint::isAllowed($subscription->endpoint)) {
            $subscription->delete();

            return;
        }
        if ($this->subscriptionsFor([(int) $subscription->user_id])->doesntContain('id', $subscriptionId)) {
            return;
        }

        $options = app(PublicHttpDestination::class)->curlOptions($subscription->endpoint);
        $report = $this->sendNotification($subscription, $payload, $options);
        if ($report->isSubscriptionExpired()) {
            $subscription->delete();
        } elseif (! $report->isSuccess()) {
            $status = $report->getResponse()?->getStatusCode();
            if ($status === null || $status === 429 || $status >= 500) {
                // Do not include endpoints or payloads in exceptions stored by the queue.
                throw new \RuntimeException('Temporary Web Push delivery failure');
            }
            Log::info('web_push_failed', ['subscription_id' => $subscriptionId, 'status' => $status]);
        }
    }

    protected function sendNotification(PushSubscription $subscription, array $payload, array $curlOptions): MessageSentReport
    {
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ],
        ], ['TTL' => ($payload['kind'] ?? '') === 'call' ? 60 : 3600,
            'urgency' => ($payload['kind'] ?? '') === 'call' ? 'high' : 'normal'], 10, [
                'handler' => HandlerStack::create(new CurlHandler),
                'allow_redirects' => false,
                'proxy' => '',
                'curl' => $curlOptions,
            ]);

        return $webPush->sendOneNotification(
            Subscription::create(['endpoint' => $subscription->endpoint,
                'keys' => ['p256dh' => $subscription->p256dh, 'auth' => $subscription->auth]]),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }
}
