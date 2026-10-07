<?php

namespace App\Services\Conversations;

use App\Data\StoredAttachment;
use App\Events\MessageChanged;
use App\Exceptions\ApiException;
use App\Exceptions\UploadException;
use App\Models\Conversations\Attachment;
use App\Models\Conversations\Message;
use App\Models\Uploads\UploadSession;
use App\Models\User;
use App\Services\Account\DemoGuestService;
use App\Services\Uploads\ImageCompressor;
use App\Support\FileName;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Приём вложений. Маленькие файлы (до uploads.encrypt_max_bytes) шифруются на диске целиком,
 * большие хранятся как есть и отдаются потоком с Range.
 *
 * Каждое вложение — строка в attachments: по ним считаются квота пользователя, вытеснение старых
 * файлов при нехватке места и срок хранения. Для отдачи метаданные лежат в meta сообщения.
 */
class AttachmentService
{
    private const DISK = 'local';

    /** Метку «открывали» пишем не чаще раза в 10 минут: плеер шлёт много Range-запросов подряд. */
    private const ACCESS_TOUCH_MINUTES = 10;

    public function __construct(
        private readonly EncryptionService $encryption,
        private readonly ImageCompressor $images,
    ) {}

    /** Файл из одного запроса (POST /messages/attachment): такие всегда под лимитом шифрования. */
    public function store(UploadedFile $file, int $userId, int $channelId): array
    {
        return DB::transaction(function () use ($file, $userId, $channelId) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $this->assertQuota($userId, (int) $file->getSize());
            $stored = $this->storeEncrypted(
                (string) $file->getRealPath(),
                FileName::sanitize($file->getClientOriginalName()),
                $file->getMimeType() ?? 'application/octet-stream',
                $userId, $channelId,
            );
            $this->assertQuota($userId, 0);

            return $stored;
        });
    }

    /**
     * Превращает собранный докачкой временный файл во вложение: маленький шифруется, большой
     * переносится как есть. При заданном destination исходник сохраняется до коммита
     * загрузки. message_id потом заполняет linkToMessage.
     *
     * @return array meta вложения для Message::meta['attachment'] вместе с id строки attachments
     */
    public function finalizeFromTemp(string $tmpPath, int $userId, int $channelId, string $clientName, string $mime, ?string $destination = null): array
    {
        $name = FileName::sanitize($clientName);
        $size = filesize($tmpPath) ?: 0;

        if ($size > (int) config('uploads.encrypt_max_bytes')) {
            return $this->moveUnencrypted($tmpPath, $size, $name, $mime, $userId, $channelId, $destination);
        }

        try {
            return $this->storeEncrypted($tmpPath, $name, $mime, $userId, $channelId, $destination);
        } finally {
            if ($destination === null) {
                @unlink($tmpPath);
            }
        }
    }

    /** Привязать вложение к сообщению, созданному после store или finalizeFromTemp. */
    public function linkToMessage(int $attachmentId, int $messageId): void
    {
        Attachment::whereKey($attachmentId)->update(['message_id' => $messageId]);
    }

    public function deleteByPath(string $diskPath): void
    {
        Storage::disk(self::DISK)->delete($diskPath);
        Attachment::where('disk_path', $diskPath)->delete();
    }

    public function usedBytes(int $userId): int
    {
        return (int) Attachment::where('user_id', $userId)->sum('size');
    }

    public function assertQuota(int $userId, int $addBytes, ?string $exceptUploadId = null): void
    {
        $isDemoGuest = User::query()->whereKey($userId)->where('demo_kind', User::DEMO_GUEST)->exists();
        $quota = $isDemoGuest ? DemoGuestService::UPLOAD_QUOTA_BYTES : (int) config('uploads.user_quota_bytes');

        $reserved = (int) UploadSession::where('user_id', $userId)->whereNull('message_id')
            ->when($exceptUploadId !== null, fn ($query) => $query->where('id', '!=', $exceptUploadId))
            ->sum('total_size');
        if ($quota > 0 && $this->usedBytes($userId) + $reserved + $addBytes > $quota) {
            throw UploadException::quotaExceeded();
        }
    }

    /**
     * Освобождает место под $neededBytes, вытесняя давно не открытые вложения
     * (по last_accessed_at, затем по created_at).
     */
    public function ensureFreeSpace(int $neededBytes): void
    {
        $minFree = (int) config('uploads.min_free_bytes');
        $root = Storage::disk(self::DISK)->path('');
        $free = @disk_free_space($root);

        // Не смогли узнать свободное место — ничего не удаляем.
        if ($free === false || $free - $neededBytes >= $minFree) {
            return;
        }

        Attachment::orderByRaw('COALESCE(last_accessed_at, created_at) asc')
            ->chunkById(50, function ($batch) use (&$free, $neededBytes, $minFree, $root) {
                foreach ($batch as $attachment) {
                    if ($free - $neededBytes >= $minFree) {
                        return false;
                    }

                    $this->deleteWithMessage($attachment);
                    $free = @disk_free_space($root) ?: $free;
                }

                return true;
            });
    }

    /**
     * Удаляет вложение целиком: файл, строку учёта и сообщение, чтобы в чате не осталось битой
     * ссылки. Так работает и вытеснение при нехватке места, и удаление из раздела «Хранилище».
     */
    public function deleteWithMessage(Attachment $attachment): void
    {
        Storage::disk(self::DISK)->delete($attachment->disk_path);

        $message = $attachment->message_id ? Message::find($attachment->message_id) : null;

        if ($message) {
            $message->delete();
            broadcast(new MessageChanged((int) $message->channels_id, (int) $message->id, 'deleted'));
        }

        $attachment->delete();
    }

    /** Файлы пользователя для раздела «Хранилище», самые крупные сначала. */
    public function filesFor(User $user): Collection
    {
        return Attachment::where('user_id', $user->id)
            ->orderByDesc('size')
            ->get(['id', 'message_id', 'channel_id', 'name', 'mime', 'size', 'kind', 'created_at']);
    }

    /** Удаляет выбранные файлы пользователя, чужие id пропускает. */
    public function deleteOwnedWithMessages(User $user, array $attachmentIds): int
    {
        $attachments = Attachment::whereIn('id', $attachmentIds)->where('user_id', $user->id)->get();

        $attachments->each(fn (Attachment $attachment) => $this->deleteWithMessage($attachment));

        return $attachments->count();
    }

    /** Файл вложения сообщения, если он ещё лежит на диске. */
    public function storedFile(Message $message): ?StoredAttachment
    {
        $attachment = is_array($message->meta) ? ($message->meta['attachment'] ?? null) : null;
        $path = $attachment['disk_path'] ?? '';

        if (! in_array($message->type, ['image', 'file'], true) || $path === '' || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return new StoredAttachment(
            diskPath: $path,
            name: $attachment['name'] ?? 'file',
            mime: $attachment['mime'] ?? 'application/octet-stream',
            kind: $attachment['kind'] ?? 'file',
            encrypted: ($attachment['encrypted'] ?? false) === true,
            keyId: (int) ($attachment['key_id'] ?? $message->key_id),
        );
    }

    public function absolutePath(StoredAttachment $file): string
    {
        return Storage::disk(self::DISK)->path($file->diskPath);
    }

    /** Маленькие зашифрованные файлы расшифровываются в памяти целиком. */
    public function decryptedContents(StoredAttachment $file): string
    {
        $contents = Storage::disk(self::DISK)->get($file->diskPath)
            ?? throw new ApiException('Файл не найден', 404);

        try {
            return $this->encryption->decryptBinary($contents, $file->keyId);
        } catch (Throwable) {
            throw new ApiException('Не удалось расшифровать файл', 422);
        }
    }

    /** При нехватке места первыми вытесняются давно не открытые файлы. */
    public function markAccessed(Message $message): void
    {
        $attachment = Attachment::where('message_id', $message->id)->first();

        if ($attachment && ! $attachment->last_accessed_at?->gt(now()->subMinutes(self::ACCESS_TOUCH_MINUTES))) {
            $attachment->forceFill(['last_accessed_at' => now()])->save();
        }
    }

    /** Картинки (кроме GIF) пережимаются в WebP, остальное шифруется как есть. */
    private function storeEncrypted(string $path, string $name, string $mime, int $userId, int $channelId, ?string $destination = null): array
    {
        $binary = file_get_contents($path);

        if ($binary === false) {
            throw new RuntimeException('Failed to read attachment');
        }

        $image = null;
        if ($this->images->canCompress($mime)) {
            try {
                $image = $this->images->compress($binary);
            } catch (RuntimeException $e) {
                // GD не смог прочитать картинку (битая, HEIC под видом JPEG и т.п.) — сохраняем как файл,
                // а не роняем загрузку целиком.
                Log::warning('attachment image not compressible, stored as file', ['mime' => $mime, 'error' => $e->getMessage()]);
            }
        }

        if ($image !== null) {
            $binary = $image['binary'];
            $extension = $image['ext'];
            $meta = [
                'name' => FileName::withExtension($name, $image['ext']),
                'mime' => $image['mime'],
                'kind' => 'image',
                'width' => $image['width'],
                'height' => $image['height'],
            ];
        } else {
            // Картинку, которую не удалось сжать, показываем файлом: браузер её, скорее всего, тоже не прочитает.
            $isImage = $this->images->isImage($mime) && ! $this->images->canCompress($mime);
            [$width, $height] = $isImage ? $this->images->dimensions($path) : [null, null];
            $extension = FileName::extension($name, $mime);
            $meta = [
                'name' => $name,
                'mime' => $mime,
                'kind' => $isImage ? 'image' : 'file',
                'width' => $width,
                'height' => $height,
            ];
        }

        $keyId = (int) config('app.encryption_actual');
        $diskPath = $destination ?? $this->buildPath($channelId, $extension, encrypted: true);

        if (! Storage::disk(self::DISK)->put($diskPath, $this->encryption->encryptBinary($binary, $keyId))) {
            throw new RuntimeException('Failed to store attachment');
        }

        return $this->createRecord([
            'disk_path' => $diskPath,
            'name' => $meta['name'],
            'mime' => $meta['mime'],
            'size' => strlen($binary),
            'kind' => $meta['kind'],
            'width' => $meta['width'],
            'height' => $meta['height'],
            'encrypted' => true,
            'key_id' => $keyId,
        ], $userId, $channelId);
    }

    private function moveUnencrypted(string $tmpPath, int $size, string $name, string $mime, int $userId, int $channelId, ?string $destination = null): array
    {
        $diskPath = $destination ?? $this->buildPath($channelId, FileName::extension($name, $mime), encrypted: false);
        $finalPath = Storage::disk(self::DISK)->path($diskPath);
        @mkdir(dirname($finalPath), 0775, true);

        // rename не работает между разными файловыми системами — тогда копируем.
        if ($destination !== null) {
            if (! @copy($tmpPath, $finalPath)) {
                throw new RuntimeException('Failed to store attachment');
            }
        } elseif (! @rename($tmpPath, $finalPath)) {
            if (! @copy($tmpPath, $finalPath)) {
                throw new RuntimeException('Failed to store attachment');
            }

            @unlink($tmpPath);
        }

        return $this->createRecord([
            'disk_path' => $diskPath,
            'name' => $name,
            'mime' => $mime,
            'size' => $size,
            'kind' => $this->images->isImage($mime) ? 'image' : 'file',
            'width' => null,
            'height' => null,
            'encrypted' => false,
            'key_id' => null,
        ], $userId, $channelId);
    }

    /** Строка учёта и meta вложения — та же форма, что лежит в Message::meta['attachment'], плюс id. */
    private function createRecord(array $meta, int $userId, int $channelId): array
    {
        $attachment = Attachment::create([
            ...$meta,
            'user_id' => $userId,
            'channel_id' => $channelId,
            'last_accessed_at' => now(),
        ]);

        return [...$meta, 'id' => $attachment->id];
    }

    private function buildPath(int $channelId, string $extension, bool $encrypted): string
    {
        return "attachments/{$channelId}/".Str::uuid()->toString().'.'.strtolower($extension).($encrypted ? '.enc' : '');
    }
}
