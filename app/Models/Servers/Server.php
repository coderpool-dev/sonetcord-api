<?php

namespace App\Models\Servers;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property bool|null $is_owner заполняет ServerController для текущего пользователя
 * @property int|null $my_permissions эффективные серверные права текущего пользователя, заполняет ServerController
 * @property int|null $my_top_position позиция высшей роли текущего пользователя (у владельца null), заполняет ServerController
 * @property bool|null $has_unread есть непрочитанный канал, заполняет ServerController::index
 */
class Server extends Model
{
    use HasFactory;

    protected $table = 'servers';

    protected $fillable = [
        'name',
        'description',
        'icon',
        'banner',
        'banner_color',
        'owner_id',
        'tags',
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<ServerMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ServerMember::class);
    }

    /** @return HasMany<ServerRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(ServerRole::class);
    }

    /** @return HasMany<ServerChannel, $this> */
    public function channels(): HasMany
    {
        return $this->hasMany(ServerChannel::class);
    }

    /** @return HasMany<ServerInvite, $this> */
    public function invites(): HasMany
    {
        return $this->hasMany(ServerInvite::class);
    }

    /** @return HasMany<ServerBan, $this> */
    public function bans(): HasMany
    {
        return $this->hasMany(ServerBan::class);
    }
}
