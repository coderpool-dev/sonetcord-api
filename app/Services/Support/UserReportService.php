<?php

namespace App\Services\Support;

use App\Data\AdminListFilters;
use App\Data\UserReportData;
use App\Exceptions\ApiException;
use App\Models\Support\UserReport;
use App\Models\User;
use App\Support\LikePattern;

/** Жалобы пользователей друг на друга. */
class UserReportService
{
    private const ADMIN_LIST_LIMIT = 200;

    public function submit(User $reporter, UserReportData $report, string $ip): void
    {
        if ($report->targetId === (int) $reporter->id) {
            throw new ApiException('Нельзя пожаловаться на себя', 422);
        }

        if (! User::query()->whereKey($report->targetId)->exists()) {
            throw new ApiException('Пользователь не найден', 404);
        }

        // Пока прошлая жалоба на этого человека не разобрана, новая запись не создаётся.
        UserReport::query()->firstOrCreate(
            [
                'reporter_id' => $reporter->id,
                'target_id' => $report->targetId,
                'status' => UserReport::STATUS_NEW,
            ],
            [
                'reason' => $report->reason,
                'comment' => $report->comment !== null ? trim($report->comment) : null,
                'ip' => $ip,
            ],
        );
    }

    public function listForAdmin(AdminListFilters $filters): array
    {
        $reports = UserReport::query()
            ->with(['reporter', 'target'])
            ->where('status', ($filters->status ?? null) ?: UserReport::STATUS_NEW)
            ->when($filters->q ?? null, function ($query, string $search) {
                $pattern = LikePattern::contains($search);
                $matchesUser = fn ($user) => $user->where('login', 'like', $pattern)->orWhere('name', 'like', $pattern);

                $query->where(fn ($query) => $query
                    ->where('comment', 'like', $pattern)
                    ->orWhereHas('target', $matchesUser)
                    ->orWhereHas('reporter', $matchesUser));
            })
            ->orderByDesc('id')
            ->limit(self::ADMIN_LIST_LIMIT)
            ->get();

        // Одна жалоба и десять разбираются по-разному, поэтому показываем общее число.
        $reportCounts = UserReport::query()
            ->selectRaw('target_id, COUNT(*) as total')
            ->whereIn('target_id', $reports->pluck('target_id')->unique())
            ->groupBy('target_id')
            ->pluck('total', 'target_id');

        return [
            'reports' => $reports
                ->map(fn (UserReport $report) => $report->toAdminArray((int) ($reportCounts[$report->target_id] ?? 0)))
                ->all(),
            'counts' => [
                'new' => UserReport::query()->where('status', UserReport::STATUS_NEW)->count(),
                'total' => UserReport::query()->count(),
            ],
        ];
    }
}
