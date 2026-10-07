<?php

namespace App\Http\Controllers\API\Admin;

use App\Data\SupportThreadFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListSupportThreadsRequest;
use App\Http\Requests\Admin\UpdateSupportThreadRequest;
use App\Http\Requests\Support\StoreSupportMessageRequest;
use App\Http\Resources\SupportMessageResource;
use App\Http\Resources\SupportThreadResource;
use App\Models\Support\SupportThread;
use App\Services\Support\SupportThreadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportThreadController extends Controller
{
    public function __construct(private readonly SupportThreadService $support) {}

    public function index(ListSupportThreadsRequest $request): JsonResponse
    {
        $threads = $this->support->listThreadsForAdmin(SupportThreadFilters::fromArray($request->validated()));

        return $this->successResponse('Обращения', [
            'threads' => SupportThreadResource::collection($threads)->resolve($request),
        ]);
    }

    public function show(Request $request, SupportThread $thread): JsonResponse
    {
        $thread = $this->support->openThread($thread, byStaff: true);

        return $this->successResponse('Обращение', [
            'thread' => (new SupportThreadResource($thread))->resolve($request),
            'messages' => SupportMessageResource::collection($thread->messages)->resolve($request),
        ]);
    }

    public function storeMessage(StoreSupportMessageRequest $request, SupportThread $thread): JsonResponse
    {
        $message = $this->support->postStaffMessage($request->user(), $thread, $request->body(), $request->uploadedFiles());

        return $this->successResponse('Ответ отправлен', [
            'message' => (new SupportMessageResource($message))->resolve($request),
        ], 201);
    }

    public function update(UpdateSupportThreadRequest $request, SupportThread $thread): JsonResponse
    {
        $changes = $request->validated();

        if (isset($changes['status'])) {
            $thread = $this->support->setStatus($thread, $changes['status']);
        }

        if (isset($changes['category'])) {
            $thread = $this->support->setCategory($thread, $changes['category']);
        }

        return $this->successResponse('Обновлено', [
            'thread' => [
                'id' => $thread->id,
                'status' => $thread->status,
                'category' => $thread->category,
            ],
        ]);
    }
}
