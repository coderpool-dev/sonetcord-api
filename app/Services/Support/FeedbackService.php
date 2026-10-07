<?php

namespace App\Services\Support;

use App\Data\AdminListFilters;
use App\Data\FeedbackData;
use App\Models\Support\FeedbackMessage;
use App\Models\User;
use App\Support\LikePattern;

/** Сообщения с формы обратной связи на сайте. */
class FeedbackService
{
    private const ADMIN_LIST_LIMIT = 200;

    /** Отправить форму может и гость, тогда автор — null. */
    public function submit(FeedbackData $form, ?User $author, string $ip, string $country, ?string $userAgent): FeedbackMessage
    {
        return FeedbackMessage::query()->create([
            'name' => trim($form->name),
            'email' => mb_strtolower(trim($form->email)),
            'body' => trim($form->body),
            'page' => $form->page ?? null,
            'status' => FeedbackMessage::STATUS_NEW,
            'user_id' => $author?->id,
            'ip' => $ip,
            'country' => $country,
            'user_agent' => mb_substr((string) $userAgent, 0, 512),
        ]);
    }

    public function listForAdmin(AdminListFilters $filters): array
    {
        $messages = FeedbackMessage::query()
            ->with('user')
            ->when(
                $filters->status ?? null,
                fn ($query, string $status) => $query->where('status', $status),
                // Без фильтра показываем всё, кроме архива.
                fn ($query) => $query->whereIn('status', [FeedbackMessage::STATUS_NEW, FeedbackMessage::STATUS_READ]),
            )
            ->when($filters->q ?? null, function ($query, string $search) {
                $pattern = LikePattern::contains($search);

                $query->where(fn ($query) => $query
                    ->where('name', 'like', $pattern)
                    ->orWhere('email', 'like', $pattern)
                    ->orWhere('body', 'like', $pattern));
            })
            ->orderByDesc('id')
            ->limit(self::ADMIN_LIST_LIMIT)
            ->get();

        return [
            'messages' => $messages->map(fn (FeedbackMessage $message) => $message->toAdminArray())->all(),
            'counts' => [
                'new' => FeedbackMessage::query()->where('status', FeedbackMessage::STATUS_NEW)->count(),
                'total' => FeedbackMessage::query()->count(),
            ],
        ];
    }
}
