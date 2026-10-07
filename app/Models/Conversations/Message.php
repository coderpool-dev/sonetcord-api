<?php

namespace App\Models\Conversations;

use App\Events\MessageSent;
use App\Models\Servers\ServerChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    protected $table = 'messages';

    protected $fillable = ['user_id', 'channels_id', 'server_channel_id', 'reply_to_id', 'type', 'message', 'meta', 'mentions', 'key_id', 'edited_at'];

    protected $casts = [
        'meta' => 'array',
        // Только у сообщений каналов сервера: {users, roles, everyone, here} — см. ServerMentionService.
        'mentions' => 'array',
        'edited_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channels_id');
    }

    /** @return BelongsTo<ServerChannel, $this> сообщение в текстовом канале сервера — альтернатива channels_id */
    public function serverChannel(): BelongsTo
    {
        return $this->belongsTo(ServerChannel::class, 'server_channel_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    /** @return HasMany<MessageReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * Создаёт системное сообщение (создание чата, выход, кик, звонок и т.п.)
     * и рассылает его участникам канала через тот же канал, что и обычные сообщения.
     */
    public static function createSystem(int $channelId, int $actorId, string $event, array $meta, string $text): self
    {
        $meta = array_merge(['event' => $event], $meta);

        $message = self::create([
            'user_id' => $actorId,
            'channels_id' => $channelId,
            'type' => 'system',
            'message' => $text,
            'meta' => $meta,
        ]);

        $actor = User::find($actorId);

        if ($actor) {
            broadcast(new MessageSent($actor, $text, $channelId, 'system', $meta));
        }

        return $message;
    }
}
