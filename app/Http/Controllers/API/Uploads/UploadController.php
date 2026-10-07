<?php

namespace App\Http\Controllers\API\Uploads;

use App\Data\UploadFileData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Uploads\CompleteUploadRequest;
use App\Http\Requests\Uploads\InitUploadRequest;
use App\Http\Requests\Uploads\UploadChunkRequest;
use App\Models\Conversations\Channel;
use App\Models\Uploads\UploadSession;
use App\Services\Uploads\UploadService;
use Illuminate\Http\JsonResponse;

/** Докачиваемая загрузка больших файлов. Протокол описан в UploadService. */
class UploadController extends Controller
{
    public function __construct(private readonly UploadService $uploads) {}

    public function store(InitUploadRequest $request): JsonResponse
    {
        $channel = $request->channel();
        $this->authorize('createMessage', $channel);

        $upload = $this->uploads->start(
            $request->user(),
            (int) $channel->id,
            UploadFileData::fromArray($request->validated()),
        );

        return $this->successResponse('Загрузка начата', [
            'upload_id' => $upload->id,
            'chunk_bytes' => (int) config('uploads.chunk_bytes'),
            'received' => 0,
        ], 201);
    }

    public function show(UploadSession $upload): JsonResponse
    {
        $this->authorize('manage', $upload);

        return $this->successResponse('Состояние загрузки', [
            'received' => (int) $upload->received_size,
            'total' => (int) $upload->total_size,
        ]);
    }

    public function update(UploadChunkRequest $request, UploadSession $upload): JsonResponse
    {
        $this->authorize('manage', $upload);
        $this->authorize('createMessage', Channel::findOrFail($upload->channel_id));

        $received = $this->uploads->appendChunk($upload, $request->offset(), $request->chunk());

        return $this->successResponse('Кусок принят', ['received' => $received]);
    }

    public function complete(CompleteUploadRequest $request, UploadSession $upload): JsonResponse
    {
        $this->authorize('manage', $upload);
        $this->authorize('createMessage', Channel::findOrFail($upload->channel_id));

        $message = $this->uploads->complete(
            $upload,
            $request->user(),
            $request->caption(),
            $request->replyToId(),
        );

        return $this->successResponse('Файл отправлен', ['message' => $message->id], 201);
    }
}
