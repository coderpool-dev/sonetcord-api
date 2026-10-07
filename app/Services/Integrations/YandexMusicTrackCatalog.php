<?php

namespace App\Services\Integrations;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Данные трека (название, исполнитель, обложка) с кэшем: они не меняются, а Ynison без исполнителя
 * присылает один и тот же трек на каждом опросе — без кэша это был HTTP-запрос к API каждые 8–10 с.
 */
class YandexMusicTrackCatalog
{
    public const TTL_SECONDS = 86400;

    public function __construct(
        private readonly YandexMusicApiClient $api,
        private readonly CacheRepository $cache,
    ) {}

    public function track(string $accessToken, string $trackId): ?array
    {
        $key = 'yandex-music:track:'.$trackId;
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $track = $this->api->track($accessToken, $trackId);

        // Неудачу не кэшируем: следующий опрос попробует снова.
        if ($track !== null) {
            $this->cache->put($key, $track, self::TTL_SECONDS);
        }

        return $track;
    }
}
