<?php

namespace App\Models\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserReport extends Model
{
    protected $table = 'user_reports';

    public const STATUS_NEW = 'new';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_DISMISSED = 'dismissed';

    public const REASONS = ['harassment', 'spam', 'scam', 'content', 'other'];

    /** Подписи для админки — чтобы не дублировать словарь на фронте и в письмах. */
    public const REASON_LABELS = [
        'harassment' => 'Преследование или угрозы',
        'spam' => 'Спам',
        'scam' => 'Мошенничество',
        'content' => 'Недопустимый контент',
        'other' => 'Другое',
    ];

    protected $fillable = [
        'reporter_id',
        'target_id',
        'reason',
        'comment',
        'status',
        'ip',
    ];

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_id');
    }

    private static function userSummary(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'login' => $user->login,
            'name' => $user->name,
            'avatar' => $user->avatar,
        ];
    }

    public function toAdminArray(int $targetReportCount = 0): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'reason_label' => self::REASON_LABELS[$this->reason] ?? $this->reason,
            'comment' => $this->comment,
            'status' => $this->status,
            'ip' => $this->ip,
            'created_at' => $this->created_at?->toIso8601String(),
            'reporter' => self::userSummary($this->relationLoaded('reporter') ? $this->reporter : null),
            'target' => self::userSummary($this->relationLoaded('target') ? $this->target : null),
            // Сколько всего жалоб на этого пользователя — один донос и десять
            // разбираются по-разному.
            'target_report_count' => $targetReportCount,
        ];
    }
}
