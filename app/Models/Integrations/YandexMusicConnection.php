<?php

namespace App\Models\Integrations;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YandexMusicConnection extends Model
{
    protected $table = 'yandex_music_connections';

    /** Трек без свежих данных дольше этого считается закончившимся. */
    public const TRACK_GRACE_SECONDS = 60;

    protected $fillable = [
        'user_id',
        'access_token',
        'refresh_token',
        'expires_at',
        'yandex_login',
        'yandex_uid',
        'last_track_key',
        'current_track_title',
        'current_track_artist',
        'current_track_album',
        'current_track_cover_url',
        'current_track_url',
        'current_track_duration_ms',
        'current_track_progress_ms',
        'current_track_paused',
        'current_track_seen_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'yandex_uid' => 'integer',
        'current_track_duration_ms' => 'integer',
        'current_track_progress_ms' => 'integer',
        'current_track_paused' => 'boolean',
        'current_track_seen_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Трек с последней синхронизации, если она была недавно. */
    public function currentTrack(): ?array
    {
        if (! $this->current_track_title || ! $this->current_track_seen_at
            || $this->current_track_seen_at->lt(now()->subSeconds(self::TRACK_GRACE_SECONDS))) {
            return null;
        }

        return [
            'title' => $this->current_track_title,
            'artist' => $this->current_track_artist ?? '',
            'album' => $this->current_track_album,
            'cover_url' => $this->current_track_cover_url,
            'track_url' => $this->current_track_url,
            'duration_ms' => $this->current_track_duration_ms,
            'progress_ms' => $this->current_track_progress_ms,
            'paused' => $this->current_track_paused,
            'seen_at' => $this->current_track_seen_at->toIso8601String(),
        ];
    }
}
