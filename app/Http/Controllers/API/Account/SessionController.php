<?php

namespace App\Http\Controllers\API\Account;

use App\Data\SessionContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Models\Account\PersonalAccessToken;
use App\Services\Account\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(private readonly SessionService $sessions) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $this->sessions->currentToken($user);

        if ($currentToken) {
            $this->sessions->refreshLocation($currentToken, SessionContext::fromRequest($request));
        }

        $currentTokenId = $currentToken ? (int) $currentToken->getKey() : null;

        $sessions = $this->sessions->tokensFor($user)
            ->map(fn (PersonalAccessToken $token) => (new SessionResource($token, $currentTokenId))->resolve($request));

        return $this->successResponse('Список сессий успешно получен', [
            'sessions' => $sessions,
            'current_session_id' => $currentTokenId,
        ]);
    }

    public function destroy(Request $request, int $tokenId): JsonResponse
    {
        $this->sessions->revoke($request->user(), $tokenId);

        return $this->successResponse('Сессия успешно завершена');
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $revoked = $this->sessions->revokeAll($request->user());

        return $this->successResponse('Выход выполнен со всех устройств', ['revoked' => $revoked]);
    }
}
