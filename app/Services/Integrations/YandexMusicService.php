<?php

namespace App\Services\Integrations;

use App\Events\UserProfileUpdated;
use App\Models\Integrations\YandexMusicConnection;
use App\Models\Integrations\YandexMusicTrackHistory;
use App\Models\User;
use App\Support\YandexMusicStatus;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/** Что пользователь слушает в Яндекс Музыке: текущий трек, статус «Слушает …» и история прослушиваний. */
class YandexMusicService
{
    public const SYNC_MIN_INTERVAL_SECONDS = 8;

    public function __construct(
        private readonly YandexYnisonService $ynison,
        private readonly YandexMusicApiClient $api,
        private readonly YandexMusicPlaybackTracker $playback,
        private readonly CacheRepository $cache,
    ) {}

    /** Узнаёт у Яндекса текущий трек и обновляет по нему подключение, статус и историю. */
    public function syncNowPlaying(User $user): ?array
    {
        $user->loadMissing('yandexMusicConnection');
        $connection = $user->yandexMusicConnection;

        if (! $connection) {
            return null;
        }

        // К Яндексу ходим не чаще раза в SYNC_MIN_INTERVAL_SECONDS на пользователя: один опрос — это два
        // WSS-подключения с TLS (~300 мс CPU), а десктоп и вкладка браузера одного человека опрашивают параллельно.
        if (! $this->cache->add('yandex-music:sync:'.$user->id, true, self::SYNC_MIN_INTERVAL_SECONDS)) {
            return $this->lastKnownTrack($user, $connection);
        }

        $track = $this->fetchCurrentTrack($connection->access_token);

        // Яндекс не ответил или ничего не играет — отдаём последний известный трек или разбираем статус.
        if ($track === null) {
            return $this->lastKnownTrack($user, $connection);
        }

        if (! filled($track['artist'] ?? null)) {
            $track['artist'] = $this->knownArtist($user, (string) ($track['title'] ?? ''), $connection) ?? '';
        }

        $trackKey = mb_strtolower(trim($track['artist'].'|'.($track['title'] ?? '')));
        $previousTrackKey = $connection->last_track_key;

        // Прошлый опрос не знал исполнителя этой же песни — это не новая песня, а уточнение.
        if ($previousTrackKey !== null && $previousTrackKey !== $trackKey && str_starts_with($previousTrackKey, '|')
            && str_ends_with($trackKey, $previousTrackKey)) {
            $this->adoptArtistlessHistory($user, $previousTrackKey, $trackKey);
            $previousTrackKey = $trackKey;
        }

        $hadCover = filled($connection->current_track_cover_url);

        $connection->last_track_key = $trackKey;
        $connection->fill($this->playback->connectionAttributes($track, $connection, $trackKey, $previousTrackKey));

        if (! filled($connection->current_track_cover_url)) {
            $cover = $this->knownCover($user, $trackKey, $previousTrackKey, $connection->getOriginal('current_track_cover_url'));

            if ($cover !== null) {
                $connection->current_track_cover_url = $cover;
                $track['cover_url'] = $cover;
            }
        }

        $connection->save();

        $this->recordHistory($user, $track, $trackKey, isNewPlay: $previousTrackKey !== $trackKey);
        $this->updateMusicStatus($user, $track, coverAppeared: ! $hadCover && filled($connection->current_track_cover_url));

        // Клиенту отдаём трек из подключения с устойчивым прогрессом, а не сырой ответ Ynison:
        // тот часто присылает progress_ms = 0, и фронт показывал бы 00:00 посреди песни.
        return $connection->currentTrack() ?? $track;
    }

    public function history(User $user, int $limit = 12): array
    {
        return YandexMusicTrackHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_played_at')
            ->limit(max(1, min($limit, 50)))
            ->get()
            ->map(fn (YandexMusicTrackHistory $track) => [
                'title' => $track->title,
                'artist' => $track->artist ?? '',
                'album' => $track->album,
                'cover_url' => $track->cover_url,
                'track_url' => $track->track_url,
                'play_count' => $track->play_count,
                'first_played_at' => $track->first_played_at?->toIso8601String(),
                'last_played_at' => $track->last_played_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Что играет, знает Ynison. Очередь — самая свежая из REST и бывает старой очередью браузера с другим
     * треком, поэтому из неё берём только недостающие поля и только если это тот же трек: иначе название
     * было бы от одной песни, а длительность и прогресс — от другой.
     */
    private function fetchCurrentTrack(string $accessToken): ?array
    {
        $playback = $this->ynison->fetchCurrentTrack($accessToken);

        if ($playback !== null && filled($playback['artist'] ?? null) && filled($playback['cover_url'] ?? null)
            && $this->positiveInt($playback['duration_ms'] ?? null) !== null) {
            return $playback;
        }

        $queueTrack = $this->api->currentQueueTrack($accessToken);

        if ($playback === null || $queueTrack === null) {
            return $playback ?? $queueTrack;
        }

        if (! $this->isSameTrack($playback, $queueTrack)) {
            return $playback;
        }

        return [
            ...$playback,
            'artist' => filled($playback['artist'] ?? null) ? $playback['artist'] : $queueTrack['artist'],
            'album' => $playback['album'] ?? $queueTrack['album'],
            'cover_url' => $playback['cover_url'] ?? $queueTrack['cover_url'],
            'track_url' => $playback['track_url'] ?? $queueTrack['track_url'],
            'duration_ms' => $this->positiveInt($playback['duration_ms'] ?? null) ?? $this->positiveInt($queueTrack['duration_ms']),
        ];
    }

    /** Тот же трек: совпал id из ссылки, а если ссылки нет — название. */
    private function isSameTrack(array $left, array $right): bool
    {
        $leftId = $this->trackIdFromUrl($left['track_url'] ?? null);
        $rightId = $this->trackIdFromUrl($right['track_url'] ?? null);

        if ($leftId !== null && $rightId !== null) {
            return $leftId === $rightId;
        }

        return $this->normalize($left['title'] ?? '') === $this->normalize($right['title'] ?? '');
    }

    private function trackIdFromUrl(?string $url): ?string
    {
        return $url !== null && preg_match('~/track/(\d+)~', $url, $match) ? $match[1] : null;
    }

    private function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }

    /**
     * Ynison иногда присылает трек без исполнителя (и API треков не ответил). Без этого у той же песни
     * менялся ключ: в истории появлялся дубль без исполнителя, а прогресс считался заново, как у новой песни.
     */
    private function knownArtist(User $user, string $title, YandexMusicConnection $connection): ?string
    {
        $normalizedTitle = $this->normalize($title);

        if ($normalizedTitle === '') {
            return null;
        }

        if (filled($connection->current_track_artist) && $this->normalize($connection->current_track_title) === $normalizedTitle) {
            return (string) $connection->current_track_artist;
        }

        $artist = YandexMusicTrackHistory::query()
            ->where('user_id', $user->id)
            ->where('title', $title)
            ->where('artist', '!=', '')
            ->orderByDesc('last_played_at')
            ->value('artist');

        return filled($artist) ? (string) $artist : null;
    }

    /** В «не беспокоить» и невидимке статус «Слушает …» не выставляем. */
    private function updateMusicStatus(User $user, array $track, bool $coverAppeared): void
    {
        if (in_array($user->presence, ['dnd', 'invisible'], true)) {
            return;
        }

        $status = YandexMusicStatus::format($track['artist'], $track['title']);
        $statusChanged = $user->music_status_text !== $status;

        if ($statusChanged) {
            $user->update(['music_status_text' => $status]);
        }

        if ($statusChanged || $coverAppeared) {
            broadcast(new UserProfileUpdated($user->fresh(['yandexMusicConnection']), $user->activeChannelIds()));
        }
    }

    /** Трек из прошлой синхронизации, а если его нет — разобранный автоматический статус «Слушает …». */
    private function lastKnownTrack(User $user, YandexMusicConnection $connection): ?array
    {
        return $connection->currentTrack() ?? YandexMusicStatus::parse($this->autoMusicStatus($user));
    }

    private function autoMusicStatus(User $user): ?string
    {
        foreach ([$user->music_status_text, $user->status_text] as $status) {
            if (YandexMusicStatus::isAuto($status)) {
                return $status;
            }
        }

        return null;
    }

    /** Обложка того же трека: из прошлой синхронизации или из истории прослушиваний. */
    private function knownCover(User $user, string $trackKey, ?string $previousTrackKey, mixed $previousCover): ?string
    {
        if ($trackKey === $previousTrackKey && filled($previousCover)) {
            return (string) $previousCover;
        }

        $historyCover = YandexMusicTrackHistory::query()
            ->where('user_id', $user->id)
            ->where('track_key', $trackKey)
            ->whereNotNull('cover_url')
            ->value('cover_url');

        return filled($historyCover) ? (string) $historyCover : null;
    }

    /** Запись истории без исполнителя переходит к записи с исполнителем, чтобы песня не была в истории дважды. */
    private function adoptArtistlessHistory(User $user, string $artistlessKey, string $trackKey): void
    {
        $artistless = YandexMusicTrackHistory::query()->where('user_id', $user->id)->where('track_key', $artistlessKey)->first();

        if (! $artistless) {
            return;
        }

        $target = YandexMusicTrackHistory::query()->where('user_id', $user->id)->where('track_key', $trackKey)->first();

        if (! $target) {
            $artistless->update(['track_key' => $trackKey]);

            return;
        }

        $target->play_count += $artistless->play_count;
        $target->first_played_at = collect([$target->first_played_at, $artistless->first_played_at])->filter()->min();
        $target->cover_url ??= $artistless->cover_url;
        $target->track_url ??= $artistless->track_url;
        $target->save();
        $artistless->delete();
    }

    private function recordHistory(User $user, array $track, string $trackKey, bool $isNewPlay): void
    {
        $history = YandexMusicTrackHistory::firstOrNew(['user_id' => $user->id, 'track_key' => $trackKey]);

        $history->fill([
            'title' => $track['title'] ?? '',
            'artist' => $track['artist'] ?? '',
            'album' => $track['album'] ?? null,
            'cover_url' => $track['cover_url'] ?? $history->cover_url,
            'track_url' => $track['track_url'] ?? $history->track_url,
            'last_played_at' => now(),
        ]);

        if (! $history->exists) {
            $history->first_played_at = now();
            $history->play_count = 1;
        } elseif ($isNewPlay) {
            $history->play_count++;
        }

        $history->save();
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
