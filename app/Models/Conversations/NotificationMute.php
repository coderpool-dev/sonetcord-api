<?php

namespace App\Models\Conversations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** «Заглушить» чат / канал сервера / сервер — см. App\Services\Conversations\NotificationMuteService. */
class NotificationMute extends Model
{
    protected $table = 'notification_mutes';

    public const TYPE_CHANNEL = 'channel';

    public const TYPE_SERVER = 'server';

    public const TYPE_SERVER_CHANNEL = 'server_channel';

    public const TYPES = [self::TYPE_CHANNEL, self::TYPE_SERVER, self::TYPE_SERVER_CHANNEL];

    protected $fillable = ['user_id', 'target_type', 'target_id', 'muted_until'];

    protected $casts = ['muted_until' => 'datetime'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query->whereNull('muted_until')->orWhere('muted_until', '>', now()));
    }
}
