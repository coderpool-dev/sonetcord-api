<?php

namespace App\Services\Integrations;

use App\Support\YandexMusicUrls;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/** HTTP API Яндекс Музыки: аккаунт, очереди воспроизведения и треки. */
class YandexMusicApiClient
{
    private const BASE_URL = 'https://api.music.yandex.net';

    /**
     * @return array{uid: mixed, login: string|null}
     *
     * @throws InvalidArgumentException токен не подошёл
     */
    public function account(string $accessToken): array
    {
        $response = $this->authorizedRequest($accessToken)->get(self::BASE_URL.'/account/status');

        if (! $response->successful()) {
            throw new InvalidArgumentException('Недействительный токен Яндекс Музыки');
        }

        $account = $response->json('result.account') ?? [];

        return [
            'uid' => $account['uid'] ?? null,
            'login' => $account['login'] ?? $account['displayName'] ?? null,
        ];
    }

    /**
     * Текущий трек самой свежей очереди. Если слушать в приложении, а не в браузере,
     * очереди часто пустые — для этого есть Ynison.
     */
    public function currentQueueTrack(string $accessToken): ?array
    {
        $response = $this->authorizedRequest($accessToken)->get(self::BASE_URL.'/queues');

        if (! $response->successful()) {
            return null;
        }

        $queues = $response->json('result.queues') ?? [];
        usort($queues, fn (array $left, array $right) => strcmp((string) ($right['modified'] ?? ''), (string) ($left['modified'] ?? '')));

        foreach ($queues as $queue) {
            $queueId = $queue['id'] ?? null;
            $track = is_string($queueId) && $queueId !== '' ? $this->trackFromQueue($accessToken, $queueId) : null;

            if ($track !== null) {
                return [...$track, 'progress_ms' => null, 'paused' => false];
            }
        }

        return null;
    }

    /** Трек по id вида «трек» или «трек:альбом». */
    public function track(string $accessToken, string $trackId): ?array
    {
        $response = $this->authorizedRequest($accessToken)->get(self::BASE_URL.'/tracks/'.rawurlencode($trackId));

        if (! $response->successful()) {
            return null;
        }

        $node = $response->json('result.0') ?? $response->json('result');

        return is_array($node) ? $this->parseTrack($node) : null;
    }

    private function trackFromQueue(string $accessToken, string $queueId): ?array
    {
        $response = $this->authorizedRequest($accessToken)->get(self::BASE_URL.'/queues/'.rawurlencode($queueId));

        if (! $response->successful()) {
            return null;
        }

        $queue = $response->json('result') ?? [];
        $index = (int) ($queue['currentIndex'] ?? $queue['current_index'] ?? -1);
        $node = $index >= 0 ? ($queue['tracks'][$index] ?? null) : null;

        if (! is_array($node)) {
            return null;
        }

        // Трек в очереди бывает вложен в track, бывает лежит сразу, а бывает только id — тогда дозапрашиваем.
        $track = is_array($node['track'] ?? null) ? $this->parseTrack($node['track']) : null;
        $track ??= $this->parseTrack($node);

        if ($track !== null) {
            return $track;
        }

        $trackId = $this->queueTrackId($node);

        return $trackId !== null ? $this->track($accessToken, $trackId) : null;
    }

    private function queueTrackId(array $node): ?string
    {
        if (! empty($node['id'])) {
            $albumId = $node['albumId'] ?? $node['album_id'] ?? null;

            return $albumId ? $node['id'].':'.$albumId : (string) $node['id'];
        }

        return empty($node['trackId']) ? null : (string) $node['trackId'];
    }

    private function parseTrack(array $node): ?array
    {
        $title = trim((string) ($node['title'] ?? ''));

        if ($title === '') {
            return null;
        }

        $album = is_array($node['albums'][0] ?? null) ? $node['albums'][0] : [];

        return [
            'title' => $title,
            'artist' => collect($node['artists'] ?? [])
                ->map(fn ($artist) => is_array($artist) ? ($artist['name'] ?? null) : null)
                ->filter()
                ->implode(', '),
            'album' => $album['title'] ?? null,
            'cover_url' => YandexMusicUrls::cover($node['coverUri'] ?? $album['coverUri'] ?? null),
            'track_url' => YandexMusicUrls::track(
                $node['id'] ?? $node['realId'] ?? $node['trackId'] ?? null,
                $album['id'] ?? $node['albumId'] ?? $node['album_id'] ?? null,
            ),
            'duration_ms' => isset($node['durationMs']) ? (int) $node['durationMs'] : null,
        ];
    }

    private function authorizedRequest(string $accessToken): PendingRequest
    {
        return Http::timeout(8)->withHeaders([
            'Authorization' => 'OAuth '.$accessToken,
            'Accept' => 'application/json',
            'X-Yandex-Music-Client' => 'SonetCord/1.0',
            'X-Yandex-Music-Device' => 'os=Windows; os_version=10; manufacturer=SonetCord; model=Desktop; clid=; device_id=goydacord; uuid=goydacord',
        ]);
    }
}
