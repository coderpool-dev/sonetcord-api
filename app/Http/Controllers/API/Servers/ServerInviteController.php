<?php

namespace App\Http\Controllers\API\Servers;

use App\Data\CreateServerInviteData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Servers\StoreServerInviteRequest;
use App\Http\Resources\ServerInviteResource;
use App\Http\Resources\ServerResource;
use App\Models\Servers\Server;
use App\Models\Servers\ServerInvite;
use App\Services\Servers\ServerAuditLogService;
use App\Services\Servers\ServerInviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerInviteController extends Controller
{
    public function __construct(
        private readonly ServerInviteService $invites,
        private readonly ServerAuditLogService $audit,
    ) {}

    public function index(Server $server): JsonResponse
    {
        $this->authorize('manageInvites', $server);

        $invites = $this->invites->listForServer($server)
            ->map(fn (ServerInvite $invite) => new ServerInviteResource($invite));

        return $this->successResponse('Список приглашений успешно получен', ['invites' => $invites]);
    }

    public function store(StoreServerInviteRequest $request, Server $server): JsonResponse
    {
        $this->authorize('createInvite', $server);

        $invite = $this->invites->create($server, $request->user(), CreateServerInviteData::fromArray($request->validated()));
        $this->audit->record($server, $request->user(), 'invite.create', 'invite', $invite->id, $invite->code, array_filter([
            'max_uses' => $invite->max_uses ? ['new' => $invite->max_uses] : null,
            'expires_at' => $invite->expires_at ? ['new' => $invite->expires_at->toIso8601String()] : null,
        ]));

        return $this->successResponse('Приглашение создано', ['invite' => new ServerInviteResource($invite)], 201);
    }

    public function destroy(Request $request, Server $server, ServerInvite $invite): JsonResponse
    {
        $this->authorize('manageInvites', $server);

        $this->invites->revoke($invite);
        $this->audit->record($server, $request->user(), 'invite.revoke', 'invite', $invite->id, $invite->code);

        return $this->successResponse('Приглашение отозвано');
    }

    /** Публичный, без auth — превью до логина (название/иконка сервера, годно ли приглашение). */
    public function preview(string $code): JsonResponse
    {
        return $this->successResponse('Приглашение найдено', $this->invites->preview($code));
    }

    public function join(Request $request, string $code): JsonResponse
    {
        $server = $this->invites->join($request->user(), $code);

        return $this->successResponse('Вы вступили на сервер', ['server' => new ServerResource($server)]);
    }
}
