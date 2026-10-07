<?php

namespace App\Http\Controllers\API\Servers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Calls\CallHeartbeatRequest;
use App\Http\Requests\Calls\CallSessionRequest;
use App\Http\Requests\Calls\StoreScreenPreviewRequest;
use App\Http\Resources\ServerVoiceParticipantResource;
use App\Models\Servers\ServerChannel;
use App\Models\User;
use App\Services\Conversations\CallScreenPreviewService;
use App\Services\Conversations\LiveKitService;
use App\Services\Servers\ServerChannelCallService;
use App\Services\Servers\ServerMemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Голосовые каналы сервера: войти/выйти/пинг. Участвовать могут только участники сервера — ServerChannelPolicy::call. */
class ServerChannelCallController extends Controller
{
    public function __construct(
        private readonly ServerChannelCallService $calls,
        private readonly CallScreenPreviewService $screenPreviews,
        private readonly LiveKitService $liveKit,
        private readonly ServerMemberService $members,
    ) {}

    public function store(CallSessionRequest $request, ServerChannel $serverChannel): JsonResponse
    {
        $this->authorize('call', $serverChannel);

        $call = $this->calls->join($request->user(), $serverChannel, $request->sessionId());

        // LiveKit настроен — клиент подключается к комнате SFU, иначе по-старому P2P.
        $user = $request->user();
        $call['livekit'] = $this->liveKit->connectionFor(
            $user,
            $serverChannel,
            $this->members->displayName((int) $serverChannel->server_id, $user),
            $call['can_speak'] && ! $call['voice_muted'],
        );

        return $this->successResponse('Вы в голосовом канале', ['call' => $call]);
    }

    public function leave(CallSessionRequest $request, ServerChannel $serverChannel): JsonResponse
    {
        $this->authorize('call', $serverChannel);

        $outcome = $this->calls->leave($request->user(), $serverChannel, $request->sessionId());

        return $this->successResponse($outcome->message());
    }

    public function heartbeat(CallHeartbeatRequest $request, ServerChannel $serverChannel): JsonResponse
    {
        // Права, посчитанные политикой, переиспользует и ответ с ограничениями голоса (ServerChannelAccess).
        $this->authorize('call', $serverChannel);

        $sessionState = $this->calls->heartbeat(
            $request->user(),
            $serverChannel,
            $request->sessionId(),
            $request->boolean('screen_sharing'),
        );

        return $this->successResponse('Сессия звонка активна', $sessionState);
    }

    public function participants(Request $request, ServerChannel $serverChannel): JsonResponse
    {
        $this->authorize('call', $serverChannel);

        $participants = ServerVoiceParticipantResource::collection($this->calls->participants($serverChannel));

        return $this->successResponse('Список участников успешно получен', [
            'participants' => $participants->resolve($request),
        ]);
    }

    public function storeScreenPreview(StoreScreenPreviewRequest $request, ServerChannel $serverChannel): JsonResponse
    {
        $this->authorize('call', $serverChannel);

        $this->screenPreviews->storeFrameForServerChannel($request->user(), (int) $serverChannel->id, $request->file('image')->get());

        return $this->successResponse('Превью обновлено');
    }

    public function showScreenPreview(Request $request, ServerChannel $serverChannel, User $user): Response
    {
        $this->authorize('call', $serverChannel);

        $jpeg = $this->screenPreviews->latestFrameForServerChannel((int) $serverChannel->id, (int) $user->id);

        if ($jpeg === null) {
            return response()->noContent();
        }

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'no-store',
        ]);
    }
}
