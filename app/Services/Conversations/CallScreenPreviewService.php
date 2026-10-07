<?php

namespace App\Services\Conversations;

use App\Exceptions\ApiException;
use App\Models\Conversations\Call;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/** Миниатюры демонстрации экрана: последний кадр лежит в кэше, пока стример присылает новые. */
class CallScreenPreviewService
{
    /** Кадр живёт чуть дольше интервала отправки: один пропущенный тик не гасит превью. */
    private const TTL_SECONDS = 25;

    public function __construct(private readonly CallPresenceService $presence) {}

    /** Кадр принимаем только от того, кто сейчас показывает экран, иначе превью можно подделать. */
    public function storeFrame(User $user, int $channelId, string $jpeg): void
    {
        $call = Call::activeIn($channelId);

        if (! $call || ! $this->presence->isScreenSharing($call->call_id, (int) $user->id)) {
            throw new ApiException('Демонстрация экрана не запущена', 409);
        }

        Cache::put($this->frameCacheKey($call->call_id, (int) $user->id), base64_encode($jpeg), self::TTL_SECONDS);
        Cache::put($this->frameTimeCacheKey($call->call_id, (int) $user->id), now()->timestamp, self::TTL_SECONDS);
    }

    /** JPEG последнего кадра или null, если превью нет. */
    public function latestFrame(int $channelId, int $userId): ?string
    {
        $call = Call::activeIn($channelId);
        $encoded = $call ? Cache::get($this->frameCacheKey($call->call_id, $userId)) : null;

        return is_string($encoded) ? (base64_decode($encoded, true) ?: null) : null;
    }

    /** То же самое, но для голосового канала сервера — зеркало storeFrame(). */
    /** $ttlSeconds длиннее обычного — для демо-стрима, у которого кадр не обновляется. */
    public function storeFrameForServerChannel(User $user, int $serverChannelId, string $jpeg, int $ttlSeconds = self::TTL_SECONDS): void
    {
        $call = Call::activeInServerChannel($serverChannelId);

        if (! $call || ! $this->presence->isScreenSharing($call->call_id, (int) $user->id)) {
            throw new ApiException('Демонстрация экрана не запущена', 409);
        }

        Cache::put($this->frameCacheKey($call->call_id, (int) $user->id), base64_encode($jpeg), $ttlSeconds);
        Cache::put($this->frameTimeCacheKey($call->call_id, (int) $user->id), now()->timestamp, $ttlSeconds);
    }

    /** То же самое, но для голосового канала сервера — зеркало latestFrame(). */
    public function latestFrameForServerChannel(int $serverChannelId, int $userId): ?string
    {
        $call = Call::activeInServerChannel($serverChannelId);
        $encoded = $call ? Cache::get($this->frameCacheKey($call->call_id, $userId)) : null;

        return is_string($encoded) ? (base64_decode($encoded, true) ?: null) : null;
    }

    /** Время последнего кадра: по нему фронт понимает, что миниатюра есть, и обновляет её. */
    public function lastFrameTime(string $callId, int $userId): ?int
    {
        $frameTime = Cache::get($this->frameTimeCacheKey($callId, $userId));

        return $frameTime === null ? null : (int) $frameTime;
    }

    private function frameCacheKey(string $callId, int $userId): string
    {
        return "screen_preview:{$callId}:{$userId}";
    }

    private function frameTimeCacheKey(string $callId, int $userId): string
    {
        return "screen_preview_at:{$callId}:{$userId}";
    }
}
