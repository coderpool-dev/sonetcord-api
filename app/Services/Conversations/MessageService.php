<?php

namespace App\Services\Conversations;

use App\Data\AttachmentResult;
use App\Data\PushNotificationData;
use App\Enums\ChannelType;
use App\Events\MessageChanged;
use App\Events\MessageSent;
use App\Events\ServerChannelMessageChanged;
use App\Events\ServerChannelMessageSent;
use App\Models\Conversations\Channel;
use App\Models\Conversations\Message;
use App\Models\Conversations\UserRecentSticker;
use App\Models\Servers\ServerChannel;
use App\Models\User;
use App\Services\Account\PushNotificationService;
use App\Services\Servers\ServerMentionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Сообщения ЛС/групп и текстовых каналов сервера — один пайплайн. Ровно один из
 * ($channelId, $serverChannelId) задан у каждого вызова; вложения/стикеры/реакции/
 * шифрование общие для обоих случаев, разнится только вещание (см. broadcastSent/
 * broadcastChanged) — у server_channels и channels разные последовательности id,
 * которые совпадают по значению, поэтому приватные каналы Echo остаются раздельными
 * (messages.{id} vs server-messages.{id}).
 */
class MessageService
{
    /** Сколько последних сообщений отдаём при открытии чата. */
    private const CHANNEL_HISTORY_LIMIT = 50;

    private const RECENT_STICKERS_SHOWN = 24;

    private const RECENT_STICKERS_KEPT = 32;

    public function __construct(
        private readonly EncryptionService $encryption,
        private readonly AttachmentService $attachments,
        private readonly ServerMentionService $mentions,
        private readonly ChannelService $channels,
        private readonly PushNotificationService $push,
        private readonly NotificationMuteService $mutes,
    ) {}

    /**
     * Пуш о сообщении в ЛС/группе всем участникам, кроме автора. Каналы сервера — нет (там шумно,
     * как в Discord: уведомляют только упоминания — см. ServerMentionService).
     */
    private function pushDirectMessage(User $user, int $channelId, string $preview): void
    {
        $recipients = $this->channels->activeMemberIds($channelId)
            ->reject(fn ($id) => (int) $id === (int) $user->id)
            ->all();
        $recipients = $this->mutes->withoutMuted($recipients, ['channel' => $channelId]);
        if ($recipients === []) {
            return;
        }

        $channel = Channel::query()->find($channelId);
        $isGroup = $channel && $channel->status === ChannelType::Group;
        $author = (string) ($user->name ?? $user->login);
        $preview = Str::limit($preview !== '' ? $preview : 'Сообщение', 140);

        $this->push->sendToUsers($recipients, PushNotificationData::fromArray([
            'title' => $isGroup ? ($channel->name ?: 'Группа') : $author,
            'body' => $isGroup ? "{$author}: {$preview}" : $preview,
            'url' => "/channels/{$channelId}",
            'tag' => "dm-{$channelId}",
            'icon' => User::getAvatarUrl($user->avatar, $user->updated_at?->toISOString()),
            'kind' => 'message',
        ]));
    }

    /**
     * Последние CHANNEL_HISTORY_LIMIT сообщений; с $beforeId — предыдущая «страница» (старше этого
     * сообщения): фронт подгружает её при прокрутке вверх, так доступна вся история.
     * Сортировка по id, а не по created_at: id строго растёт и даёт стабильный курсор.
     */
    public function latestInChannel(?int $channelId, ?int $serverChannelId = null, ?int $beforeId = null): Collection
    {
        return Message::with(['user.yandexMusicConnection', 'replyTo.user', 'reactions'])
            ->where($this->targetColumn($channelId, $serverChannelId))
            ->when($beforeId, fn ($query) => $query->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->take(self::CHANNEL_HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->values()
            ->each(function (Message $message) {
                if ($message->replyTo) {
                    $this->decryptMessage($message->replyTo);
                }

                $this->decryptMessage($message);
            });
    }

    public function storeText(User $user, ?int $channelId, ?int $serverChannelId, string $plain, ?int $replyToId): Message
    {
        $this->assertReplyInChannel($replyToId, $channelId, $serverChannelId);

        $encrypted = $this->encryption->encrypt($plain, $this->currentKeyId());
        $serverChannel = $serverChannelId !== null ? ServerChannel::query()->find($serverChannelId) : null;
        $mentions = $serverChannel ? $this->mentions->resolve($user, $serverChannel, $plain) : null;

        $message = Message::create([
            'user_id' => $user->id,
            ...$this->targetColumn($channelId, $serverChannelId),
            'reply_to_id' => $replyToId,
            'message' => $encrypted['data'],
            'mentions' => $mentions,
            'key_id' => $encrypted['key_id'],
        ]);

        $this->broadcastSent($user, $plain, $channelId, $serverChannelId);

        if ($serverChannel && $mentions) {
            $this->mentions->notify($message, $user, $serverChannel, $mentions, $plain);
        }
        if ($channelId !== null) {
            $this->pushDirectMessage($user, $channelId, $plain);
        }

        return $message;
    }

    public function storeAttachment(
        User $user,
        ?int $channelId,
        ?int $serverChannelId,
        UploadedFile $file,
        string $caption = '',
        ?int $replyToId = null,
    ): Message {
        $this->assertReplyInChannel($replyToId, $channelId, $serverChannelId);

        $stored = $this->attachments->store($file, (int) $user->id, $channelId ?? $serverChannelId);

        return $this->createAttachmentMessage($user, $channelId, $serverChannelId, $stored, $caption, $replyToId);
    }

    /** Сообщение для файла, который уже лежит в хранилище (например, собран докачкой). */
    public function storeFinalizedAttachment(
        User $user,
        ?int $channelId,
        ?int $serverChannelId,
        AttachmentResult $stored,
        string $caption = '',
        ?int $replyToId = null,
    ): Message {
        $this->assertReplyInChannel($replyToId, $channelId, $serverChannelId);

        return $this->createAttachmentMessage($user, $channelId, $serverChannelId, $stored, $caption, $replyToId);
    }

    public function storeSticker(User $user, ?int $channelId, ?int $serverChannelId, string $stickerId, ?int $replyToId): Message
    {
        $this->assertReplyInChannel($replyToId, $channelId, $serverChannelId);

        $message = Message::create([
            'user_id' => $user->id,
            ...$this->targetColumn($channelId, $serverChannelId),
            'reply_to_id' => $replyToId,
            'type' => 'sticker',
            'message' => '',
            'meta' => ['sticker' => $stickerId],
            'key_id' => $this->currentKeyId(),
        ]);

        $this->broadcastSent($user, '', $channelId, $serverChannelId, 'sticker', ['sticker' => $stickerId]);
        $this->rememberSticker((int) $user->id, $stickerId);
        if ($channelId !== null) {
            $this->pushDirectMessage($user, $channelId, 'Стикер');
        }

        return $message;
    }

    public function updateText(Message $message, string $plain): void
    {
        $encrypted = $this->encryption->encrypt($plain, $this->currentKeyId());
        // Упоминания пересчитываем (подсветка должна совпадать с текстом), но заново не уведомляем —
        // как в Discord, правка не пингует.
        $serverChannel = $message->server_channel_id !== null ? ServerChannel::query()->find($message->server_channel_id) : null;

        $message->update([
            'message' => $encrypted['data'],
            'key_id' => $encrypted['key_id'],
            'edited_at' => now(),
            ...($serverChannel ? ['mentions' => $this->mentions->resolve($message->user, $serverChannel, $plain)] : []),
        ]);

        $this->broadcastChanged($message, 'updated');
    }

    public function delete(Message $message): void
    {
        $attachment = is_array($message->meta) ? ($message->meta['attachment'] ?? null) : null;

        if (is_array($attachment) && ! empty($attachment['disk_path'])) {
            $this->attachments->deleteByPath($attachment['disk_path']);
        }

        $message->delete();
        $this->broadcastChanged($message, 'deleted');
    }

    /** @return bool true — реакция поставлена, false — снята */
    public function toggleReaction(User $user, Message $message, string $emoji): bool
    {
        $added = DB::transaction(function () use ($user, $message, $emoji): bool {
            Message::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();
            $reaction = $message->reactions()
                ->where('user_id', $user->id)
                ->where('emoji', $emoji)
                ->first();

            if ($reaction) {
                $reaction->delete();
            } else {
                $message->reactions()->create(['user_id' => $user->id, 'emoji' => $emoji]);
            }

            return $reaction === null;
        }, 3);

        $this->broadcastChanged($message, 'reaction');

        return $added;
    }

    public function recentStickers(User $user): Collection
    {
        return UserRecentSticker::where('user_id', $user->id)
            ->orderByDesc('last_used_at')
            ->limit(self::RECENT_STICKERS_SHOWN)
            ->pluck('sticker_id')
            ->values();
    }

    private function createAttachmentMessage(
        User $user,
        ?int $channelId,
        ?int $serverChannelId,
        AttachmentResult $stored,
        string $caption,
        ?int $replyToId,
    ): Message {
        $caption = trim($caption);
        $keyId = $this->currentKeyId();
        $serverChannel = $serverChannelId !== null && $caption !== '' ? ServerChannel::query()->find($serverChannelId) : null;
        $mentions = $serverChannel ? $this->mentions->resolve($user, $serverChannel, $caption) : null;

        $message = Message::create([
            'user_id' => $user->id,
            ...$this->targetColumn($channelId, $serverChannelId),
            'reply_to_id' => $replyToId,
            'type' => $stored->kind === 'image' ? 'image' : 'file',
            'message' => $caption !== '' ? $this->encryption->encrypt($caption, $keyId)['data'] : '',
            'meta' => ['attachment' => $stored->toArray()],
            'mentions' => $mentions,
            'key_id' => $keyId,
        ]);

        if (! empty($stored->id)) {
            $this->attachments->linkToMessage((int) $stored->id, (int) $message->id);
        }

        DB::afterCommit(function () use ($user, $caption, $channelId, $serverChannelId, $message, $serverChannel, $mentions) {
            $this->broadcastSent($user, $caption, $channelId, $serverChannelId, $message->type, ['attachment' => true]);

            if ($serverChannel && $mentions) {
                $this->mentions->notify($message, $user, $serverChannel, $mentions, $caption);
            }
            if ($channelId !== null) {
                $this->pushDirectMessage($user, $channelId, $caption !== ''
                    ? "📎 {$caption}"
                    : ($message->type === 'image' ? '📷 Фото' : '📎 Файл'));
            }
        });

        return $message;
    }

    /** @return array{channels_id: int}|array{server_channel_id: int} */
    private function targetColumn(?int $channelId, ?int $serverChannelId): array
    {
        return $channelId !== null
            ? ['channels_id' => $channelId]
            : ['server_channel_id' => $serverChannelId];
    }

    private function broadcastSent(
        User $user,
        string $plain,
        ?int $channelId,
        ?int $serverChannelId,
        string $type = 'text',
        ?array $meta = null,
    ): void {
        if ($channelId !== null) {
            broadcast(new MessageSent($user, $plain, $channelId, $type, $meta));
        } else {
            broadcast(new ServerChannelMessageSent($user, $plain, $serverChannelId, $type, $meta));
        }
    }

    private function broadcastChanged(Message $message, string $action): void
    {
        if ($message->channels_id !== null) {
            broadcast(new MessageChanged((int) $message->channels_id, (int) $message->id, $action));
        } else {
            broadcast(new ServerChannelMessageChanged((int) $message->server_channel_id, (int) $message->id, $action));
        }
    }

    private function assertReplyInChannel(?int $messageId, ?int $channelId, ?int $serverChannelId): void
    {
        if (! $messageId) {
            return;
        }

        $exists = Message::whereKey($messageId)
            ->where($this->targetColumn($channelId, $serverChannelId))
            ->where('type', '!=', 'system')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'reply_to_id' => ['Сообщение для ответа из другого канала'],
            ]);
        }
    }

    /**
     * Подменяет зашифрованный текст расшифрованным только в памяти: исходное значение
     * синхронизируется, чтобы случайный save() не записал открытый текст в базу.
     */
    private function decryptMessage(Message $message): void
    {
        if ($message->type === 'system' || $message->message === '') {
            return;
        }

        try {
            $message->message = $this->encryption->decrypt($message->message, (int) $message->key_id);
        } catch (\Exception) {
            $message->message = 'not decrypted';
        }

        $message->syncOriginalAttribute('message');
    }

    private function rememberSticker(int $userId, string $stickerId): void
    {
        $recent = UserRecentSticker::firstOrNew([
            'user_id' => $userId,
            'sticker_id' => $stickerId,
        ]);
        $recent->used_count = $recent->exists ? ((int) $recent->used_count) + 1 : 1;
        $recent->last_used_at = now();
        $recent->save();

        $keepIds = UserRecentSticker::where('user_id', $userId)
            ->orderByDesc('last_used_at')
            ->limit(self::RECENT_STICKERS_KEPT)
            ->pluck('id');

        UserRecentSticker::where('user_id', $userId)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    private function currentKeyId(): int
    {
        return (int) config('app.encryption_actual');
    }
}
