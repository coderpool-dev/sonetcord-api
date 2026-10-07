<?php

namespace App\Http\Controllers\API\Conversations;

use App\Http\Controllers\Controller;
use App\Http\Responses\RangedFileResponse;
use App\Models\Conversations\Message;
use App\Services\Conversations\AttachmentService;
use App\Services\Conversations\ChannelService;
use App\Support\ContentDisposition;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly ChannelService $channels,
    ) {}

    /**
     * Отдаёт файл вложения. Подпись в адресе (middleware signed) + срок жизни ссылки.
     * user в query — тот, кому выписали ссылку: если его выгнали из беседы, файл больше не отдаём.
     */
    public function show(Request $request, Message $message): Response
    {
        $viewerId = (int) $request->query('user');
        if ($viewerId < 1 || ! $this->channels->isMember($viewerId, (int) $message->channels_id)) {
            abort(403, 'Нет доступа');
        }

        $file = $this->attachments->storedFile($message) ?? abort(404);
        $this->attachments->markAccessed($message);

        $headers = [
            'Content-Disposition' => ContentDisposition::make($file->opensInBrowser() ? 'inline' : 'attachment', $file->name),
            'Cache-Control' => 'private, max-age=86400',
        ];

        if ($file->encrypted) {
            return RangedFileResponse::fromContents($request, $this->attachments->decryptedContents($file), $file->mime, $headers);
        }

        $nginxPrefix = config('filesystems.private_x_accel_prefix');

        return $nginxPrefix
            ? RangedFileResponse::viaNginx($nginxPrefix, $file->diskPath, $file->mime, $headers)
            : RangedFileResponse::fromFile($request, $this->attachments->absolutePath($file), $file->mime, $headers);
    }
}
