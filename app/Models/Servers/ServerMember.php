<?php

namespace App\Models\Servers;

use App\Enums\ServerMembershipStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ServerMember extends Model
{
    protected $table = 'server_members';

    protected $fillable = ['server_id', 'user_id', 'nickname', 'status', 'joined_at', 'voice_muted', 'voice_deafened'];

    protected $casts = [
        'status' => ServerMembershipStatus::class,
        'joined_at' => 'datetime',
        'voice_muted' => 'boolean',
        'voice_deafened' => 'boolean',
    ];

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<ServerRole, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(ServerRole::class, 'server_member_role');
    }

    /** Участник, которого не исключили, не забанили и который не вышел сам. */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', '!=', ServerMembershipStatus::Removed);
    }
}
