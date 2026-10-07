<?php

namespace App\Http\Controllers\API\Servers;

use App\Data\CreateServerData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Servers\StoreServerRequest;
use App\Http\Requests\Servers\UpdateServerRequest;
use App\Http\Resources\ServerResource;
use App\Models\Servers\Server;
use App\Models\User;
use App\Services\Servers\ServerAuditLogService;
use App\Services\Servers\ServerChannelReadService;
use App\Services\Servers\ServerRoleService;
use App\Services\Servers\ServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function __construct(
        private readonly ServerService $servers,
        private readonly ServerRoleService $roles,
        private readonly ServerAuditLogService $audit,
        private readonly ServerChannelReadService $reads,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $servers = $this->servers->serversFor($user)
            ->load('members')
            ->map(function (Server $server) use ($user) {
                // Точка у сервера в левой колонке — есть непрочитанный канал.
                $server->has_unread = $this->reads->hasUnread($user, $server);

                return new ServerResource($this->withUserAccess($server, $user));
            });

        return $this->successResponse('Список серверов успешно получен', ['servers' => $servers]);
    }

    public function store(StoreServerRequest $request): JsonResponse
    {
        $server = $this->servers->create($request->user(), CreateServerData::fromArray($request->validated()));

        return $this->successResponse('Сервер успешно создан', ['server' => new ServerResource($this->withUserAccess($server, $request->user()))], 201);
    }

    public function update(UpdateServerRequest $request, Server $server): JsonResponse
    {
        $this->authorize('update', $server);

        $oldName = $server->name;
        $updated = $this->servers->update(
            (int) $server->id,
            $request->validated('name'),
            $request->file('icon'),
            $request->boolean('remove_icon'),
        );
        $changes = array_filter([
            'name' => $updated->name !== $oldName ? ['old' => $oldName, 'new' => $updated->name] : null,
            'icon' => $request->file('icon') ? ['new' => 'загружена'] : ($request->boolean('remove_icon') ? ['new' => 'убрана'] : null),
        ]);
        if ($changes !== []) {
            $this->audit->record($updated, $request->user(), 'server.update', 'server', $updated->id, $updated->name, $changes);
        }

        return $this->successResponse('Сервер обновлён', ['server' => new ServerResource($this->withUserAccess($updated, $request->user()))]);
    }

    /** «Отметить как прочитанное» — все видимые текстовые каналы сервера. */
    public function markRead(Request $request, Server $server): JsonResponse
    {
        $this->authorize('view', $server);

        $this->reads->markServerRead($request->user(), $server);

        return $this->successResponse('Сервер отмечен прочитанным');
    }

    public function destroy(Server $server): JsonResponse
    {
        $this->authorize('delete', $server);

        $this->servers->delete((int) $server->id);

        return $this->successResponse('Сервер удалён');
    }

    public function leave(Request $request, Server $server): JsonResponse
    {
        $this->authorize('leave', $server);

        $this->servers->leave($request->user(), (int) $server->id);

        return $this->successResponse('Вы покинули сервер');
    }

    private function withUserAccess(Server $server, User $user): Server
    {
        $actor = $this->roles->actorFor($server, $user);
        $server->is_owner = $actor->isOwner;
        $server->my_permissions = $actor->permissions;
        // Позиция своей высшей роли — фронт по ней блокирует роли и участников выше себя.
        // У владельца null: он над всеми.
        $server->my_top_position = $actor->isOwner ? null : max(0, $actor->topPosition);

        return $server;
    }
}
