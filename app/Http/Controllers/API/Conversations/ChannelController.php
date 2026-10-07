<?php

namespace App\Http\Controllers\API\Conversations;

use App\Data\CreateChannelData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Channels\StoreChannelRequest;
use App\Http\Requests\Channels\UpdateChannelRequest;
use App\Http\Resources\ChannelResource;
use App\Http\Resources\ChannelSummaryResource;
use App\Models\Conversations\Channel;
use App\Services\Conversations\ChannelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChannelController extends Controller
{
    public function __construct(private readonly ChannelService $channels) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $channels = $this->channels->channelsFor($user)
            ->map(fn (Channel $channel) => new ChannelResource($channel, $user));

        return $this->successResponse('Список каналов успешно получен', ['channels' => $channels]);
    }

    public function store(StoreChannelRequest $request): JsonResponse
    {
        $creation = $this->channels->create($request->user(), CreateChannelData::fromArray($request->validated()));

        return $this->successResponse(
            $creation->created ? 'Канал успешно создан' : 'Личный чат уже существует, участники возвращены в чат',
            $creation->toArray(),
            $creation->created ? 201 : 200,
        );
    }

    public function update(UpdateChannelRequest $request, Channel $channel): JsonResponse
    {
        $this->authorize('update', $channel);

        $updated = $this->channels->update(
            (int) $channel->id,
            $request->validated('name'),
            $request->file('avatar'),
            $request->boolean('remove_avatar'),
        );

        return $this->successResponse('Беседа обновлена', [
            'channel' => new ChannelSummaryResource($updated),
        ]);
    }

    public function markRead(Request $request, Channel $channel): JsonResponse
    {
        $this->authorize('view', $channel);

        $this->channels->markRead($request->user(), (int) $channel->id);

        return $this->successResponse('Канал отмечен прочитанным');
    }

    public function leave(Request $request, Channel $channel): JsonResponse
    {
        $this->authorize('leave', $channel);

        $this->channels->leave($request->user(), (int) $channel->id);

        return $this->successResponse('Вы успешно покинули канал');
    }
}
