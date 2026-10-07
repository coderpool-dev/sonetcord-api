<?php

namespace App\Models\Integrations;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YandexMusicTrackHistory extends Model
{
    protected $table = 'yandex_music_track_histories';

    protected $fillable = [
        'user_id',
        'track_key',
        'title',
        'artist',
        'album',
        'cover_url',
        'track_url',
        'play_count',
        'first_played_at',
        'last_played_at',
    ];

    protected $casts = [
        'play_count' => 'integer',
        'first_played_at' => 'datetime',
        'last_played_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
