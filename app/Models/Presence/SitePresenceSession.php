<?php

namespace App\Models\Presence;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitePresenceSession extends Model
{
    protected $table = 'site_presence_sessions';

    protected $fillable = [
        'session_key',
        'user_id',
        'path',
        'referrer',
        'referrer_host',
        'country',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
