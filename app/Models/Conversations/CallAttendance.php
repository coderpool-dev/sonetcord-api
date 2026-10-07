<?php

namespace App\Models\Conversations;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Кто был в звонке: первый вход и последний heartbeat. Живёт и после звонка, в отличие от CallSession. */
class CallAttendance extends Model
{
    protected $table = 'call_attendances';

    public $timestamps = false;

    protected $fillable = ['call_id', 'user_id', 'joined_at', 'last_seen_at'];

    protected $casts = [
        'joined_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /** Запоминает участника по свежей сессии: вход пишется один раз, последний heartbeat — каждый раз. */
    public static function recordFrom(CallSession $session): void
    {
        $seenAt = $session->last_seen_at ?? now();

        static::query()->upsert(
            [[
                'call_id' => $session->call_id,
                'user_id' => $session->user_id,
                'joined_at' => $seenAt,
                'last_seen_at' => $seenAt,
            ]],
            ['call_id', 'user_id'],
            ['last_seen_at'],
        );
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
