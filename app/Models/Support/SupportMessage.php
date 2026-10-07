<?php

namespace App\Models\Support;

use App\Models\Conversations\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessage extends Model
{
    protected $table = 'support_messages';

    protected $fillable = [
        'thread_id',
        'user_id',
        'is_staff',
        'body',
        'attachment',
    ];

    protected $casts = [
        'is_staff' => 'boolean',
        'attachment' => 'array',
    ];

    /** @return BelongsTo<SupportThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(SupportThread::class, 'thread_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Скриншоты сообщения с их номерами. Старые сообщения хранят одну картинку прямо
     * в attachment, новые — список в attachment.items.
     *
     * @return array<int, array<string, mixed>>
     */
    public function storedAttachments(): array
    {
        $attachment = $this->attachment;

        if (! is_array($attachment)) {
            return [];
        }

        $items = is_array($attachment['items'] ?? null) ? array_values($attachment['items']) : [$attachment];

        return array_filter($items, fn ($item) => is_array($item) && ($item['kind'] ?? '') === 'image');
    }

    /** @return array<string, mixed>|null */
    public function storedAttachment(int $index): ?array
    {
        return $this->storedAttachments()[$index] ?? null;
    }
}
