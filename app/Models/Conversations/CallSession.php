<?php

namespace App\Models\Conversations;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Подключение одного устройства к звонку. Живо, пока клиент шлёт heartbeat.
 *
 * @property int|null $screen_preview_at заполняет ServerChannelCallService::participants() —
 *                                       колонки в БД нет, это метка последнего кадра превью демонстрации из кэша
 * @property string|null $display_name ник на сервере; этот и следующие флаги заполняет
 *                                     ServerChannelCallService::participants() для голосового канала сервера
 * @property bool|null $voice_muted
 * @property bool|null $voice_deafened
 * @property bool|null $priority_speaker
 */
class CallSession extends Model
{
    use HasFactory;

    protected $table = 'call_sessions';

    /**
     * Без heartbeat дольше этого сессия удаляется. Клиент пингует примерно раз в 15 секунд,
     * запас переживает короткий рестарт бэка без массового выхода из звонка.
     */
    public const STALE_SECONDS = 120;

    /** В админке участник считается «в звонке», если heartbeat был не раньше этого. */
    public const FRESH_SECONDS = 45;

    protected $fillable = [
        'call_id',
        'user_id',
        'channel_id',
        'server_channel_id',
        'session_id',
        'last_seen_at',
        'screen_sharing',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'screen_sharing' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Сессия удаляется при выходе — для истории звонков участник запоминается отдельно.
        static::saved(function (self $session): void {
            if ($session->wasRecentlyCreated || $session->wasChanged('last_seen_at')) {
                CallAttendance::recordFrom($session);
            }
        });
    }

    /** @return BelongsTo<Call, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class, 'call_id', 'call_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
