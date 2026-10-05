<?php

namespace App\Services\Integrations;

use App\Support\YandexMusicUrls;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use WebSocket\Client;
use WebSocket\ConnectionException;

/**
 * Текущий трек через Ynison — так же, как его узнают приложения Яндекс Музыки.
 * REST-очереди часто пустые, если слушать не в браузере.
 */
class YandexYnisonService
{
    private const REDIRECT_URL = 'wss://ynison.music.yandex.ru/redirector.YnisonRedirectService/GetRedirectToYnison';

    public function __construct(private readonly YandexMusicApiClient $api) {}

    public function fetchCurrentTrack(string $accessToken): ?array
    {
        try {
            $deviceId = $this->deviceIdForAccessToken($accessToken);
            $protocol = [
                'Ynison-Device-Id' => $deviceId,
                'Ynison-Device-Info' => json_encode(['app_name' => 'Chrome', 'type' => 1], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ];

            $redirect = $this->requestRedirect(self::REDIRECT_URL, $accessToken, $this->websocketProtocol($protocol));
            $host = (string) ($redirect['host'] ?? '');
            $ticket = (string) ($redirect['redirect_ticket'] ?? '');

            if ($host === '' || $ticket === '') {
                return null;
            }

            $protocol['Ynison-Redirect-Ticket'] = $ticket;

            if (($redirect['session_id'] ?? '') !== '') {
                $protocol['Ynison-Session-Id'] = (string) $redirect['session_id'];
            }

            $client = $this->openSocket('wss://'.$host.'/ynison_state.YnisonStateService/PutYnisonState', $accessToken, $this->websocketProtocol($protocol));
            $client->send(json_encode($this->handshake($deviceId), JSON_UNESCAPED_UNICODE));
            $payload = $client->receive();
            $client->close();

            $state = is_string($payload) ? json_decode($payload, true) : null;

            return is_array($state) ? $this->parsePlayerState($state, $accessToken) : null;
        } catch (\Throwable $e) {
            Log::debug('Yandex Ynison track fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** Постоянный id устройства для токена, чтобы Яндекс не видел каждый опрос как новое устройство. */
    public function deviceIdForAccessToken(string $accessToken): string
    {
        return substr(hash('sha256', 'goydacord:'.$accessToken), 0, 16);
    }

    public function parsePlayerState(array $state, string $accessToken): ?array
    {
        $queue = $state['player_state']['player_queue'] ?? null;
        $index = (int) ($queue['current_playable_index'] ?? -1);
        $playable = is_array($queue) && $index >= 0 ? ($queue['playable_list'][$index] ?? null) : null;

        if (! is_array($playable)) {
            return null;
        }

        $title = trim((string) ($playable['title'] ?? ''));

        if ($title === '') {
            return null;
        }

        $status = is_array($state['player_state']['status'] ?? null) ? $state['player_state']['status'] : [];
        $durationMs = isset($status['duration_ms']) && (int) $status['duration_ms'] > 0 ? (int) $status['duration_ms'] : null;
        $artist = $this->extractArtistFromPlayable($playable);
        $trackId = $this->resolvePlayableTrackId($playable);

        $track = [
            'title' => $title,
            'artist' => $artist,
            'album' => $playable['album_title'] ?? null,
            'cover_url' => YandexMusicUrls::cover($playable['cover_uri'] ?? $playable['coverUri'] ?? null),
            'track_url' => YandexMusicUrls::track($trackId),
            'duration_ms' => $durationMs,
            'progress_ms' => $this->resolveLiveProgressMs($status, $durationMs),
            'paused' => $this->isPaused($status),
        ];

        // Ynison часто не присылает исполнителя или длительность — дополняем из API треков.
        $fetched = ($artist === '' || $durationMs === null) && $trackId !== null
            ? $this->api->track($accessToken, $trackId)
            : null;

        if ($fetched === null) {
            return $track;
        }

        return [
            ...$track,
            'title' => $fetched['title'] !== '' ? $fetched['title'] : $title,
            'artist' => $artist !== '' ? $artist : $fetched['artist'],
            'album' => $fetched['album'] ?? $track['album'],
            'cover_url' => $fetched['cover_url'] ?? $track['cover_url'],
            'track_url' => $fetched['track_url'] ?? $track['track_url'],
            'duration_ms' => $durationMs ?? $fetched['duration_ms'],
        ];
    }

    /** Прогресс на момент ответа: Ynison присылает его на момент последнего события, а трек с тех пор играл. */
    public function resolveLiveProgressMs(array $status, ?int $durationMs): ?int
    {
        if (! isset($status['progress_ms'])) {
            return null;
        }

        $progressMs = (int) $status['progress_ms'];
        $statusTimestampMs = (int) ($status['version']['timestamp_ms'] ?? 0);

        if (($status['paused'] ?? false) || $statusTimestampMs <= 0) {
            return $progressMs;
        }

        $elapsedMs = max(0, (int) round(microtime(true) * 1000) - $statusTimestampMs);

        return min($durationMs ?? PHP_INT_MAX, $progressMs + $elapsedMs);
    }

    /** Пауза на нулевом прогрессе приходит от «теневого» устройства и не означает, что трек стоит. */
    public function isPaused(array $status): bool
    {
        return (bool) ($status['paused'] ?? false) && (int) ($status['progress_ms'] ?? 0) !== 0;
    }

    public function extractArtistFromPlayable(array $playable): string
    {
        $artists = collect($playable['artists'] ?? [])
            ->map(fn ($artist) => is_array($artist) ? ($artist['name'] ?? null) : (is_string($artist) ? $artist : null))
            ->filter()
            ->implode(', ');

        if ($artists !== '') {
            return $artists;
        }

        $candidates = [
            [$playable, ['subtitle', 'artist', 'artist_name', 'artistName']],
            [$playable['track_info'] ?? null, ['artist', 'artist_name', 'subtitle']],
            [$playable['video_clip_info'] ?? null, ['artist', 'artist_name', 'subtitle']],
        ];

        foreach ($candidates as [$source, $fields]) {
            foreach (is_array($source) ? $fields : [] as $field) {
                $value = trim((string) ($source[$field] ?? ''));

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    public function resolvePlayableTrackId(array $playable): ?string
    {
        $id = trim((string) ($playable['playable_id'] ?? ''));

        if ($id === '') {
            return null;
        }

        $albumId = (string) ($playable['album_id_optional'] ?? $playable['album_id'] ?? '');

        return $albumId !== '' ? $id.':'.$albumId : $id;
    }

    /** Состояние «пустого пульта»: так Ynison отдаёт текущее состояние, не забирая воспроизведение себе. */
    private function handshake(string $deviceId): array
    {
        return [
            'update_full_state' => [
                'player_state' => [
                    'player_queue' => [
                        'current_playable_index' => -1,
                        'entity_id' => '',
                        'entity_type' => 'VARIOUS',
                        'playable_list' => [],
                        'options' => ['repeat_mode' => 'NONE'],
                        'entity_context' => 'BASED_ON_ENTITY_BY_DEFAULT',
                        'version' => ['device_id' => $deviceId, 'version' => 9021243204784341000, 'timestamp_ms' => 0],
                        'from_optional' => '',
                    ],
                    'status' => [
                        'duration_ms' => 0,
                        'paused' => true,
                        'playback_speed' => 1,
                        'progress_ms' => 0,
                        'version' => ['device_id' => $deviceId, 'version' => 8321822175199937000, 'timestamp_ms' => 0],
                    ],
                ],
                'device' => [
                    'capabilities' => ['can_be_player' => false, 'can_be_remote_controller' => true, 'volume_granularity' => 0],
                    'info' => ['device_id' => $deviceId, 'type' => 'WEB', 'title' => 'SonetCord', 'app_name' => 'Chrome'],
                    'volume_info' => ['volume' => 0],
                ],
                'is_currently_active' => false,
            ],
            'rid' => Str::uuid()->toString(),
            'player_action_timestamp_ms' => 0,
            'activity_interception_type' => 'DO_NOT_INTERCEPT_BY_DEFAULT',
        ];
    }

    private function requestRedirect(string $url, string $accessToken, string $protocol): array
    {
        $client = $this->openSocket($url, $accessToken, $protocol);
        $payload = $client->receive();
        $client->close();

        $redirect = is_string($payload) && $payload !== '' ? json_decode($payload, true) : null;

        if (! is_array($redirect)) {
            throw new ConnectionException('Invalid Ynison redirect response');
        }

        return $redirect;
    }

    private function websocketProtocol(array $deviceInfo): string
    {
        return 'Bearer, v2, '.rawurlencode(json_encode($deviceInfo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function openSocket(string $url, string $accessToken, string $protocol): Client
    {
        return new Client($url, [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'OAuth '.$accessToken,
                'Origin' => 'https://music.yandex.ru',
                'Sec-WebSocket-Protocol' => $protocol,
            ],
        ]);
    }
}
