<?php

namespace App\Http\Controllers\API\Account;

use App\Data\SessionContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Account\DemoGuestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Кнопка «Посмотреть демо» на лендинге: временный аккаунт с готовым сервером и чатами. */
class DemoGuestController extends Controller
{
    public function __construct(private readonly DemoGuestService $demo) {}

    public function store(Request $request): JsonResponse
    {
        $demo = $this->demo->start(SessionContext::fromRequest($request));

        return $this->successResponse('Демо-аккаунт создан', [
            'user' => new UserResource($demo->user->load('yandexMusicConnection')),
            'token' => $demo->token,
            'server_id' => $demo->serverId,
            'channel_id' => $demo->channelId,
        ], 201);
    }
}
