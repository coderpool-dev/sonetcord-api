<?php

namespace App\Models\Servers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Запись журнала аудита сервера — см. App\Services\Servers\ServerAuditLogService. */
class ServerAuditLog extends Model
{
    protected $table = 'server_audit_logs';

    public const UPDATED_AT = null;

    protected $fillable = ['server_id', 'actor_id', 'action', 'target_type', 'target_id', 'target_label', 'changes'];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
