<?php

namespace App\Models\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property bool|null $unread есть ли сообщения, которых не видела поддержка; заполняет SupportThreadService
 */
class SupportThread extends Model
{
    protected $table = 'support_threads';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const CATEGORY_INBOX = 'inbox';

    public const CATEGORY_SPAM = 'spam';

    protected $fillable = [
        'user_id',
        'status',
        'category',
        'last_message_at',
        'user_last_read_at',
        'admin_last_read_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'user_last_read_at' => 'datetime',
        'admin_last_read_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<SupportMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'thread_id');
    }

    /** @return HasOne<SupportMessage, $this> */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(SupportMessage::class, 'thread_id')->latestOfMany();
    }
}
