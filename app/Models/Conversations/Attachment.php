<?php

namespace App\Models\Conversations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $table = 'attachments';

    protected $fillable = [
        'message_id',
        'user_id',
        'channel_id',
        'disk_path',
        'name',
        'mime',
        'size',
        'kind',
        'width',
        'height',
        'encrypted',
        'key_id',
        'last_accessed_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'encrypted' => 'boolean',
        'last_accessed_at' => 'datetime',
    ];

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
