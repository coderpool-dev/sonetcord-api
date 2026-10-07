<?php

namespace App\Http\Controllers\API\Conversations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Calls\AcceptCallRequest;
use App\Http\Requests\Calls\CallHeartbeatRequest;
use App\Http\Requests\Calls\CallSessionRequest;
use App\Http\Requests\Calls\StoreScreenPreviewRequest;
use App\Http\Resources\CallResource;
use App\Models\Conversations\Call;
use App\Models\Conversations\Channel;
use App\Models\User;
use App\Services\Conversations\CallPresenceService;
use App\Services\Conversations\CallScreenPreviewService;
use App\Services\Conversations\CallService;
use App\Support\ClientBuild;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Звонки в канале. Участвовать могут только участники канала — это проверяет ChannelPolicy::call. */
class CallController extends Controller
{
    public function __construct(
        private readonly CallService $calls,
        private readonly CallPresenceService $presence,
        private readonly CallScreenPreviewService $screenPreviews,
    ) {}

    public function store(Request $request, Channel $channel): JsonResponse
    {
        $this->authorize('call', $channel);
        ClientBuild::assertSupported($request);

        $start = $this->calls->create($request->user(), (int) $channel->id);

        return $this->successResponse(
            'Данные звонка успешно получены',
            ['call' => $start->call->toArray()],
            $start->created ? 201 : 200,
        );
    }

    public function accept(AcceptCallRequest $request, Channel $channel): JsonResponse
    {
        $this->authorize('call', $channel);
        ClientBuild::assertSupported($request);

        $this->calls->accept($request->user(), (int) $channel->id, $request->sessionId(), $request->callId());
        $this->logCallDiagnostic('server_accept', $request, $channel);

        return $this->successResponse('Вы в звонке');
    }

    public function decline(Request $request, Channel $channel): JsonResponse
    {
        $this->authorize('call', $channel);

        $this->calls->decline($request->user(), (int) $channel->id);

        return $this->successResponse('Звонок отклонён');
    }

    public function leave(CallSessionRequest $request, Channel $channel): JsonResponse
    {
        $this->authorize('call', $channel);

        $callId = Call::activeIn((int) $channel->id)?->call_id;
        $outcome = $this->calls->leave($request->user(), (int) $channel->id, $request->sessionId());
        $this->logCallDiagnostic('server_leave', $request, $channel, $callId);

        return $this->successResponse($outcome->message());
    }

    public function heartbeat(CallHeartbeatRequest $request, Channel $channel): JsonResponse
    {
        $this->authorize('call', $channel);

        $sessionState = $this->presence->heartbeat(
            $request->user(),
            (int) $channel->id,
            $request->sessionId(),
            $request->boolean('screen_sharing'),
            $request->callId(),
        );
        $this->logCallDiagnostic('server_heartbeat', $request, $channel);

        return $this->successResponse('Сессия звонка активна', $sessionState);
    }

    public function active(Request $request): JsonResponse
    {
        $calls = CallResource::collection($this->calls->activeCallsForUser($request->user()));

        return $this->successResponse('Список активных звонков успешно получен', [
            'data' => $calls->resolve($request),
        ]);
    }

    public function storeScreenPreview(StoreScreenPreviewRequest $request, Channel $channel): JsonResponse
    {
        $this->authorize('call', $channel);

        $this->screenPreviews->storeFrame($request->user(), (int) $channel->id, $request->file('image')->get());

        return $this->successResponse('Превью обновлено');
    }

    public function showScreenPreview(Request $request, Channel $channel, User $user): Response
    {
        $this->authorize('call', $channel);

        $jpeg = $this->screenPreviews->latestFrame((int) $channel->id, (int) $user->id);

        if ($jpeg === null) {
            return response()->noContent();
        }

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function logCallDiagnostic(string $event, CallSessionRequest $request, Channel $channel, ?string $callId = null): void
    {
        try {
            Log::channel('calls')->info($event, [
                'schema' => 1,
                'received_at' => now()->toIso8601String(),
                'call_id' => $callId ?? Call::activeIn((int) $channel->id)?->call_id,
                'channel_id' => $channel->id,
                'user_id' => $request->user()->id,
                'login' => $request->user()->login,
                'call_session_id' => $request->sessionId(),
                'screen_sharing' => $request->boolean('screen_sharing'),
            ]);
        } catch (\Throwable) {
            // A full log disk must never disconnect a call or reject a heartbeat.
        }
    }
}
