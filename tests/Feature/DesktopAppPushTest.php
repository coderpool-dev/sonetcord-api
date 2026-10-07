<?php

namespace Tests\Feature;

use App\Models\Account\PushSubscription;
use App\Services\Account\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithCalls;
use Tests\TestCase;

class DesktopAppPushTest extends TestCase
{
    use InteractsWithCalls, RefreshDatabase;

    private const WINDOWS_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36';

    private const ANDROID_CHROME = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36';

    private const DESKTOP_APP = 'SonetCord/1.0.96 (win32) Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Electron/37.10.3 Safari/537.36';

    public function test_desktop_browser_push_is_skipped_while_the_desktop_app_runs_but_phones_still_get_it(): void
    {
        $user = $this->makeUser();
        $this->subscribe($user->id, 'pc', self::WINDOWS_CHROME);
        $this->subscribe($user->id, 'phone', self::ANDROID_CHROME);
        $push = app(PushNotificationService::class);

        $this->assertSame(['pc', 'phone'], $this->endpoints($push->subscriptionsFor([$user->id])));

        Sanctum::actingAs($user, ['*']);
        $this->withHeader('User-Agent', self::DESKTOP_APP)->postJson('/api/auth/online')->assertOk();

        $this->assertSame(['phone'], $this->endpoints($push->subscriptionsFor([$user->id])));

        // Приложение закрыли: пингов нет больше минуты — браузер на компьютере снова получает пуши.
        $this->travel(2)->minutes();
        $this->assertSame(['pc', 'phone'], $this->endpoints($push->subscriptionsFor([$user->id])));
    }

    public function test_browser_ping_does_not_count_as_desktop_app(): void
    {
        $user = $this->makeUser();
        $this->subscribe($user->id, 'pc', self::WINDOWS_CHROME);

        Sanctum::actingAs($user, ['*']);
        $this->withHeader('User-Agent', self::WINDOWS_CHROME)->postJson('/api/auth/online')->assertOk();

        $this->assertSame(['pc'], $this->endpoints(app(PushNotificationService::class)->subscriptionsFor([$user->id])));
    }

    private function subscribe(int $userId, string $name, string $userAgent): void
    {
        app(PushNotificationService::class)->subscribe($userId, "https://fcm.googleapis.com/fcm/send/{$name}", 'pk', 'au', $userAgent);
    }

    /** @return string[] */
    private function endpoints($subscriptions): array
    {
        return $subscriptions
            ->map(fn (PushSubscription $sub) => basename($sub->endpoint))
            ->sort()
            ->values()
            ->all();
    }
}
