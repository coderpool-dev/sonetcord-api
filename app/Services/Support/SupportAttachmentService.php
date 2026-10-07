<?php

namespace App\Services\Support;

use App\Data\SupportAttachmentData;
use App\Models\Support\SupportMessage;
use App\Models\User;
use App\Services\Uploads\ImageCompressor;
use App\Support\FileName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Скриншоты в обращениях в поддержку: только картинки, до 12 МБ. */
class SupportAttachmentService
{
    private const DISK = 'local';

    private const MAX_BYTES = 12 * 1024 * 1024;

    public function __construct(private readonly ImageCompressor $images) {}

    public function store(UploadedFile $file, int $threadId): SupportAttachmentData
    {
        if (($file->getSize() ?: 0) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => ['Скриншот слишком большой (макс. 12 МБ)']]);
        }

        $mime = $file->getMimeType() ?? 'application/octet-stream';

        if (! $this->images->isImage($mime)) {
            throw ValidationException::withMessages(['file' => ['Можно прикрепить только изображение']]);
        }

        $path = (string) $file->getRealPath();
        $name = FileName::sanitize($file->getClientOriginalName(), 180) ?: 'screenshot.png';
        $binary = file_get_contents($path);

        if ($binary === false) {
            throw new RuntimeException('Failed to read attachment');
        }

        if ($this->images->canCompress($mime)) {
            try {
                $image = $this->images->compress($binary);
            } catch (RuntimeException) {
                throw ValidationException::withMessages(['file' => ['Не удалось обработать изображение']]);
            }

            $binary = $image['binary'];
            $mime = $image['mime'];
            $extension = $image['ext'];
            $name = FileName::withExtension($name, $extension);
            $width = $image['width'];
            $height = $image['height'];
        } else {
            $extension = FileName::extension($name, $mime);
            [$width, $height] = $this->images->dimensions($path);
        }

        $diskPath = sprintf('support/%d/%s.%s', $threadId, Str::uuid()->toString(), $extension);
        Storage::disk(self::DISK)->put($diskPath, $binary);

        return SupportAttachmentData::fromArray([
            'disk_path' => $diskPath,
            'mime' => $mime,
            'name' => $name,
            'kind' => 'image',
            'size' => strlen($binary),
            'width' => $width,
            'height' => $height,
        ]);
    }

    /** Скриншот из обращения видят автор обращения и администраторы. */
    public function canView(SupportMessage $message, int $viewerId): bool
    {
        $thread = $message->loadMissing('thread')->thread;
        $viewer = $viewerId > 0 ? User::query()->find($viewerId) : null;

        return $thread !== null && $viewer !== null
            && ((int) $thread->user_id === (int) $viewer->id || $viewer->isAdmin());
    }

    public function streamPath(array $attachment): ?string
    {
        $path = $attachment['disk_path'] ?? '';

        if ($path === '' || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->path($path);
    }
}
