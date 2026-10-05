<?php

namespace App\Services\Integrations;

use App\Events\UserProfileUpdated;
use App\Models\Integrations\YandexMusicConnection;
use App\Models\User;
use App\Support\YandexMusicStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Привязка аккаунта Яндекс Музыки: по коду с устройства (OAuth device flow) или готовым токеном. */
class YandexMusicConnectionService
{
    private const OAUTH_BASE_URL = 'https://oauth.yandex.ru';

    public function __construct(private readonly YandexMusicApiClient $api) {}

    /** @return array{connected: bool, login: string|null} */
    public function status(User $user): array
    {
        $user->loadMissing('yandexMusicConnection');

        return [
            'connected' => $user->yandexMusicConnection !== null,
            'login' => $user->yandexMusicConnection?->yandex_login,
        ];
    }

    /** Код, который пользователь вводит на странице Яндекса. Клиент затем опрашивает pollDeviceAuth. */
    public function startDeviceAuth(User $user): array
    {
        $response = Http::asForm()->post(self::OAUTH_BASE_URL.'/device/code', [
            'client_id' => $this->clientId(),
            'device_id' => Str::lower(Str::random(10)),
            'device_name' => 'SonetCord',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Не удалось запросить код привязки Яндекс Музыки');
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 300);
        $interval = (int) ($response->json('interval') ?? 5);

        Cache::put($this->cacheKey($user), [
            'device_code' => $response->json('device_code'),
            'interval' => $interval,
            'expires_at' => now()->addSeconds($expiresIn)->timestamp,
        ], $expiresIn);

        return [
            'user_code' => $response->json('user_code'),
            'verification_url' => $response->json('verification_url') ?? 'https://oauth.yandex.ru/device',
            'expires_in' => $expiresIn,
            'interval' => $interval,
        ];
    }

    /** @return array{status: string, login?: string|null, message?: string|null} */
    public function pollDeviceAuth(User $user): array
    {
        $pending = Cache::get($this->cacheKey($user));

        if (! is_array($pending) || empty($pending['device_code'])) {
            return ['status' => 'expired'];
        }

        if (($pending['expires_at'] ?? 0) < now()->timestamp) {
            Cache::forget($this->cacheKey($user));

            return ['status' => 'expired'];
        }

        $response = Http::asForm()->post(self::OAUTH_BASE_URL.'/token', [
            'grant_type' => 'device_code',
            'code' => $pending['device_code'],
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ]);

        if ($response->status() === 400) {
            $error = (string) $response->json('error');

            // Пользователь ещё не ввёл код — клиент спросит снова.
            if (in_array($error, ['authorization_pending', 'slow_down'], true)) {
                return ['status' => 'pending'];
            }

            Cache::forget($this->cacheKey($user));

            return ['status' => 'denied', 'message' => $response->json('error_description') ?? $error];
        }

        if (! $response->successful()) {
            throw new RuntimeException('Не удалось завершить привязку Яндекс Музыки');
        }

        Cache::forget($this->cacheKey($user));
        $this->storeConnection($user, $response->json());

        return [
            'status' => 'connected',
            'login' => $user->fresh()->yandexMusicConnection?->yandex_login,
        ];
    }

    /** @return array{connected: bool, login: string|null} */
    public function connectWithToken(User $user, string $token): array
    {
        $token = trim($token);

        if ($token === '') {
            throw new InvalidArgumentException('Токен не может быть пустым');
        }

        $this->storeConnection($user, ['access_token' => $token]);

        return $this->status($user->fresh());
    }

    /** Вместе с привязкой снимаются автоматические статусы «Слушает …». */
    public function disconnect(User $user): void
    {
        $user->yandexMusicConnection()->delete();

        $updates = [];

        foreach (['music_status_text', 'status_text'] as $field) {
            if (YandexMusicStatus::isAuto($user->{$field})) {
                $updates[$field] = null;
            }
        }

        if ($updates !== []) {
            $user->update($updates);
            broadcast(new UserProfileUpdated($user->fresh(['yandexMusicConnection']), $user->activeChannelIds()));
        }
    }

    private function storeConnection(User $user, array $token): void
    {
        $accessToken = (string) ($token['access_token'] ?? '');

        if ($accessToken === '') {
            throw new InvalidArgumentException('Пустой access_token');
        }

        $account = $this->api->account($accessToken);
        $expiresIn = isset($token['expires_in']) ? (int) $token['expires_in'] : null;

        // Новая привязка начинается с чистого листа: трек мог остаться от прежнего аккаунта.
        YandexMusicConnection::query()->updateOrCreate(['user_id' => $user->id], [
            'access_token' => $accessToken,
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_at' => $expiresIn ? now()->addSeconds($expiresIn) : null,
            'yandex_login' => $account['login'],
            'yandex_uid' => $account['uid'],
            'last_track_key' => null,
            'current_track_title' => null,
            'current_track_artist' => null,
            'current_track_album' => null,
            'current_track_cover_url' => null,
            'current_track_url' => null,
            'current_track_duration_ms' => null,
            'current_track_progress_ms' => null,
            'current_track_paused' => false,
            'current_track_seen_at' => null,
        ]);
    }

    private function cacheKey(User $user): string
    {
        return 'yandex_music_auth:'.$user->id;
    }

    private function clientId(): string
    {
        return (string) config('services.yandex_music.client_id');
    }

    private function clientSecret(): string
    {
        return (string) config('services.yandex_music.client_secret');
    }
}
