<?php

namespace App\Models\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackMessage extends Model
{
    protected $table = 'feedback_messages';

    public const STATUS_NEW = 'new';

    public const STATUS_READ = 'read';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'name',
        'email',
        'body',
        'status',
        'user_id',
        'ip',
        'country',
        'page',
        'user_agent',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Плоский вид для админки — без сырых полей вроде user_agent целиком. */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'body' => $this->body,
            'status' => $this->status,
            'country' => $this->country,
            'page' => $this->page,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
            'user' => $this->relationLoaded('user') && $this->user
                ? [
                    'id' => $this->user->id,
                    'login' => $this->user->login,
                    'name' => $this->user->name,
                ]
                : null,
        ];
    }
}
