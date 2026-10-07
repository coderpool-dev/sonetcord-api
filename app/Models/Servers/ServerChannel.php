<?php

namespace App\Models\Servers;

use App\Enums\ServerChannelKind;
use App\Models\Conversations\CallSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property Collection<int, CallSession>|null $active_participants заполняет ServerChannelCallService::participants
 * @property int|null $my_permissions эффективные права запрашивающего на этом канале, заполняет ServerChannelController
 * @property int|null $last_message_id последнее сообщение канала, заполняет ServerChannelController из ServerChannelReadService
 * @property int|null $last_read_message_id до какого сообщения дочитал запрашивающий, там же
 */
class ServerChannel extends Model
{
    protected $table = 'server_channels';

    protected $fillable = ['server_id', 'category_id', 'name', 'kind', 'topic', 'position'];

    protected $casts = [
        'kind' => ServerChannelKind::class,
    ];

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<ServerChannel, $this> категория, в которую вложен канал */
    public function category(): BelongsTo
    {
        return $this->belongsTo(self::class, 'category_id');
    }

    /** @return HasMany<ServerChannel, $this> каналы внутри этой категории */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'category_id');
    }

    /** @return HasMany<ServerInvite, $this> */
    public function invites(): HasMany
    {
        return $this->hasMany(ServerInvite::class, 'channel_id');
    }

    /** @return HasMany<ServerChannelRoleOverwrite, $this> allow/deny-переопределения прав по ролям */
    public function roleOverwrites(): HasMany
    {
        return $this->hasMany(ServerChannelRoleOverwrite::class);
    }

    /** @return HasMany<ServerChannelMemberOverwrite, $this> allow/deny-переопределения прав отдельных участников */
    public function memberOverwrites(): HasMany
    {
        return $this->hasMany(ServerChannelMemberOverwrite::class);
    }
}
