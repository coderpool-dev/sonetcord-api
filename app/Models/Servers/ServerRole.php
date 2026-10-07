<?php

namespace App\Models\Servers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** @property int $permissions битовая маска App\Enums\ServerPermission */
class ServerRole extends Model
{
    protected $table = 'server_roles';

    protected $fillable = ['server_id', 'name', 'color', 'position', 'permissions', 'is_default', 'hoist', 'mentionable'];

    protected $casts = [
        'permissions' => 'integer',
        'position' => 'integer',
        'is_default' => 'boolean',
        'hoist' => 'boolean',
        'mentionable' => 'boolean',
    ];

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsToMany<ServerMember, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(ServerMember::class, 'server_member_role');
    }
}
