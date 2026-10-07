<?php

namespace App\Models\Servers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Discord-style allow/deny переопределение прав роли на конкретном канале. */
class ServerChannelRoleOverwrite extends Model
{
    protected $table = 'server_channel_role_overwrites';

    protected $fillable = ['server_channel_id', 'server_role_id', 'allow', 'deny'];

    protected $casts = [
        'allow' => 'integer',
        'deny' => 'integer',
    ];

    /** @return BelongsTo<ServerChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(ServerChannel::class, 'server_channel_id');
    }

    /** @return BelongsTo<ServerRole, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(ServerRole::class, 'server_role_id');
    }
}
