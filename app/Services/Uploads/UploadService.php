<?php

namespace App\Services\Uploads;

use App\Data\UploadFileData;
use App\Exceptions\ApiException;
use App\Exceptions\UploadException;
use App\Models\Conversations\Channel;
use App\Models\Conversations\ChannelMember;
use App\Models\Conversations\Message;
use App\Models\Uploads\UploadSession;
use App\Models\User;
use App\Services\Conversations\AttachmentService;
use App\Services\Conversations\MessageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Докачиваемая загрузка больших файлов (до uploads.max_bytes).
 *
 * start — создаёт сессию и пустой временный файл; appendChunk — дописывает кусок строго
 * с текущего смещения; complete — превращает собранный файл во вложение и сообщение.
 * Файл целиком одним запросом не приходит, поэтому post_max_size и client_max_body_size
 * должны вмещать только один кусок (uploads.chunk_bytes).
 */
class UploadService
{
    private const DISK = 'local';

    private const LOCK_SECONDS = 120;

    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly MessageService $messages,
    ) {}

    public function start(User $user, int $channelId, UploadFileData $file): UploadSession
    {
        $maxBytes = (int) config('uploads.max_bytes');
        if ($file->size > $maxBytes) {
            throw UploadException::tooLarge($maxBytes);
        }

        return DB::transaction(function () use ($user, $channelId, $file) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->attachments->assertQuota((int) $user->id, $file->size);
            $this->attachments->ensureFreeSpace($file->size);
            $upload = new UploadSession([
                'user_id' => $user->id,
                'channel_id' => $channelId,
                'filename' => $file->filename,
                'mime' => $file->mime ?: 'application/octet-stream',
                'total_size' => $file->size,
                'received_size' => 0,
            ]);
            $upload->id = $upload->newUniqueId();
            $upload->tmp_path = "tmp/uploads/{$upload->id}.part";
            $upload->save();
            if (! Storage::disk(self::DISK)->put($upload->tmp_path, '')) {
                throw UploadException::writeFailed();
            }

            return $upload;
        });
    }

    /** Дописывает кусок и возвращает, сколько байт принято всего. */
    public function appendChunk(UploadSession $upload, int $offset, string $chunk): int
    {
        $lock = Cache::lock($this->lockKey($upload), self::LOCK_SECONDS);

        // Клиент повторяет кусок после обрыва, пока первый запрос ещё пишет. Без блокировки
        // оба запроса проходят проверку смещения и кусок попадает в файл дважды.
        if (! $lock->get()) {
            throw UploadException::offsetMismatch((int) $upload->received_size);
        }

        try {
            return DB::transaction(function () use ($upload, $offset, $chunk) {
                $current = UploadSession::whereKey($upload->id)->lockForUpdate()->firstOrFail();
                if ($current->message_id !== null) {
                    throw new ApiException('Загрузка уже завершена', 409);
                }

                return $this->writeChunk($current, $offset, $chunk);
            });
        } finally {
            $lock->release();
        }
    }

    public function complete(UploadSession $upload, User $user, string $caption, ?int $replyToId): Message
    {
        $lock = Cache::lock($this->lockKey($upload), self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw UploadException::offsetMismatch((int) $upload->received_size);
        }

        try {
            $message = DB::transaction(function () use ($upload, $user, $caption, $replyToId) {
                // Same lock order as quota reservations and the upload reaper.
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $current = UploadSession::whereKey($upload->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($user)->authorize('manage', $current);
                ChannelMember::where('channels_id', $current->channel_id)
                    ->where('users_id', $user->id)->lockForUpdate()->first();
                Gate::forUser($user)->authorize('createMessage', Channel::findOrFail($current->channel_id));

                if ($current->message_id !== null) {
                    return Message::find($current->message_id)
                        ?? throw new ApiException('Сообщение загрузки уже удалено', 410);
                }

                $total = (int) $current->total_size;
                $received = (int) $current->received_size;
                if ($received !== $total) {
                    throw UploadException::incomplete($received, $total);
                }
                $path = Storage::disk(self::DISK)->path($current->tmp_path);
                clearstatcache(true, $path);
                $assembled = @filesize($path);
                if ($assembled !== $total) {
                    throw UploadException::sizeMismatch($assembled === false ? null : $assembled, $total);
                }

                $this->attachments->assertQuota((int) $user->id, $total, $current->id);
                // A stable destination can be overwritten after a crash; the source survives
                // until the message and receipt commit together.
                $stored = $this->attachments->finalizeFromTemp(
                    $path, (int) $user->id, (int) $current->channel_id,
                    $current->filename, $current->mime, $current->finalPath(),
                );
                $this->attachments->assertQuota((int) $user->id, 0, $current->id);
                $message = $this->messages->storeFinalizedAttachment(
                    $user, (int) $current->channel_id, null, $stored, $caption, $replyToId,
                );
                $current->update(['message_id' => $message->id]);

                return $message;
            });

            Storage::disk(self::DISK)->delete($upload->tmp_path);

            return $message;
        } finally {
            $lock->release();
        }
    }

    private function writeChunk(UploadSession $upload, int $offset, string $chunk): int
    {
        $received = (int) $upload->received_size;

        // Повтор уже принятого куска — просто подтверждаем, сколько есть.
        if ($offset < $received) {
            return $received;
        }

        if ($offset > $received) {
            throw UploadException::offsetMismatch($received);
        }

        $length = strlen($chunk);
        if ($length === 0) {
            throw UploadException::emptyChunk();
        }

        if ($received + $length > (int) $upload->total_size) {
            throw UploadException::exceedsDeclaredSize();
        }

        $this->appendToFile(Storage::disk(self::DISK)->path($upload->tmp_path), $chunk, $received);

        $upload->received_size = $received + $length;
        $upload->save();

        return $upload->received_size;
    }

    private function appendToFile(string $path, string $chunk, int $offset): void
    {
        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            throw UploadException::writeFailed();
        }

        try {
            // Discard bytes written by a request whose DB update did not commit.
            $stat = fstat($handle);
            if ($stat === false || $stat['size'] < $offset
                || ! ftruncate($handle, $offset) || fseek($handle, $offset) !== 0) {
                throw UploadException::writeFailed();
            }
            // fwrite может записать меньше байт, чем передали, поэтому пишем в цикле.
            $length = strlen($chunk);
            $written = 0;

            while ($written < $length) {
                $bytes = fwrite($handle, $written === 0 ? $chunk : substr($chunk, $written));
                if ($bytes === false || $bytes === 0) {
                    throw UploadException::writeFailed();
                }
                $written += $bytes;
            }

            if (! fflush($handle) || ! fsync($handle)) {
                throw UploadException::writeFailed();
            }
        } finally {
            fclose($handle);
        }
    }

    private function lockKey(UploadSession $upload): string
    {
        return "upload-session:{$upload->id}";
    }
}
