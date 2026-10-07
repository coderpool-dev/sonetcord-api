<?php

namespace App\Models\Conversations;

use App\Enums\CallStatus;
use App\Models\Servers\ServerChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Звонок в канале. Первичный ключ — UUID в call_id.
 *
 * @property Collection<int, ChannelMember>|null $active_participants заполняет CallService::activeCallsForUser
 */
class Call extends Model
{
    use HasFactory;

    protected $table = 'calls';

    protected $primaryKey = 'call_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'call_id',
        'channel_id',
        'server_channel_id',
        'initiator_id',
        'status',
        'answered',
        'system_message_id',
        'understaffed_at',
        'ended_at',
    ];

    protected $casts = [
        'status' => CallStatus::class,
        'answered' => 'boolean',
        'understaffed_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $call): void {
            if ($call->status === CallStatus::Ended && $call->ended_at === null) {
                $call->ended_at = now();
            } elseif ($call->status === CallStatus::Active && $call->ended_at !== null) {
                $call->ended_at = null;
            }
        });
    }

    /** Сколько шёл звонок: до конца или до сейчас, если ещё идёт. */
    public function durationSeconds(): int
    {
        $startedAt = $this->created_at ?? now();

        return max(0, (int) $startedAt->diffInSeconds($this->ended_at ?? now()));
    }

    /** @return HasMany<CallAttendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(CallAttendance::class, 'call_id', 'call_id');
    }

    /** Последний активный звонок в канале. */
    public static function activeIn(int $channelId): ?self
    {
        return static::query()->where('channel_id', $channelId)->active()->latest()->first();
    }

    /** Последний активный звонок в голосовом канале сервера. */
    public static function activeInServerChannel(int $serverChannelId): ?self
    {
        return static::query()->where('server_channel_id', $serverChannelId)->active()->latest()->first();
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    /** @return BelongsTo<ServerChannel, $this> звонок в голосовом канале сервера — альтернатива channel_id */
    public function serverChannel(): BelongsTo
    {
        return $this->belongsTo(ServerChannel::class, 'server_channel_id');
    }

    /** @return BelongsTo<User, $this> */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', CallStatus::Active);
    }
}
