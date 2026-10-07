<?php

namespace App\Models\Servers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Discord-style allow/deny переопределение прав конкретного участника на канале — применяется последним. */
class ServerChannelMemberOverwrite extends Model
{
    protected $table = 'server_channel_member_overwrites';

    protected $fillable = ['server_channel_id', 'server_member_id', 'allow', 'deny'];

    protected $casts = [
        'allow' => 'integer',
        'deny' => 'integer',
    ];

    /** @return BelongsTo<ServerChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(ServerChannel::class, 'server_channel_id');
    }

    /** @return BelongsTo<ServerMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(ServerMember::class, 'server_member_id');
    }
}
