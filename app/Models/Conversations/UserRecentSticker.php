<?php

namespace App\Models\Conversations;

use Illuminate\Database\Eloquent\Model;

class UserRecentSticker extends Model
{
    protected $table = 'user_recent_stickers';

    protected $fillable = [
        'user_id',
        'sticker_id',
        'used_count',
        'last_used_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];
}
