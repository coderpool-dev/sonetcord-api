<?php

namespace App\Http\Controllers\API\Servers;

use App\Data\CreateServerRoleData;
use App\Data\UpdateServerRoleData;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Servers\AssignMemberRolesRequest;
use App\Http\Requests\Servers\ReorderServerRolesRequest;
use App\Http\Requests\Servers\StoreServerRoleRequest;
use App\Http\Requests\Servers\UpdateServerRoleRequest;
use App\Http\Resources\ServerMemberResource;
use App\Http\Resources\ServerRoleResource;
use App\Models\Servers\Server;
use App\Models\Servers\ServerMember;
use App\Models\Servers\ServerRole;
use App\Services\Servers\ServerAuditLogService;
use App\Services\Servers\ServerRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerRoleController extends Controller
{
    public function __construct(
        private readonly ServerRoleService $roles,
        private readonly ServerAuditLogService $audit,
    ) {}

    public function index(Server $server): JsonResponse
    {
        $this->authorize('view', $server);

        $roles = $this->roles->listForServer($server)
            ->map(fn (ServerRole $role) => new ServerRoleResource($role));

        return $this->successResponse('Список ролей успешно получен', ['roles' => $roles]);
    }

    public function store(StoreServerRoleRequest $request, Server $server): JsonResponse
    {
        $this->authorize('manageRoles', $server);

        $role = $this->roles->create($server, $this->roles->actorFor($server, $request->user()), CreateServerRoleData::fromArray($request->validated()));
        $this->audit->record($server, $request->user(), 'role.create', 'role', $role->id, $role->name, [
            'permissions' => ['new' => $role->permissions],
        ]);

        return $this->successResponse('Роль создана', ['role' => new ServerRoleResource($role)], 201);
    }

    public function update(UpdateServerRoleRequest $request, Server $server, ServerRole $role): JsonResponse
    {
        $this->authorize('manageRoles', $server);
        $this->assertRoleBelongsToServer($server, $role);

        $before = $role->only(['name', 'color', 'permissions', 'hoist', 'mentionable']);
        $updated = $this->roles->update($role, $this->roles->actorFor($server, $request->user()), UpdateServerRoleData::fromArray($request->validated()));
        $changes = collect($updated->only(array_keys($before)))
            ->filter(fn ($value, $key) => $value != $before[$key])
            ->map(fn ($value, $key) => ['old' => $before[$key], 'new' => $value])
            ->all();
        if ($changes !== []) {
            $this->audit->record($server, $request->user(), 'role.update', 'role', $updated->id, $updated->is_default ? '@everyone' : $updated->name, $changes);
        }

        return $this->successResponse('Роль обновлена', ['role' => new ServerRoleResource($updated)]);
    }

    public function destroy(Request $request, Server $server, ServerRole $role): JsonResponse
    {
        $this->authorize('manageRoles', $server);
        $this->assertRoleBelongsToServer($server, $role);

        $this->roles->delete($role, $this->roles->actorFor($server, $request->user()));
        $this->audit->record($server, $request->user(), 'role.delete', 'role', $role->id, $role->name);

        return $this->successResponse('Роль удалена');
    }

    public function reorder(ReorderServerRolesRequest $request, Server $server): JsonResponse
    {
        $this->authorize('manageRoles', $server);

        $reordered = $this->roles->reorder($server, $this->roles->actorFor($server, $request->user()), $request->validated('role_ids'));
        $this->audit->record($server, $request->user(), 'role.reorder', null, null, null, [
            'order' => $reordered->where('is_default', false)->sortByDesc('position')->pluck('name')->values()->all(),
        ]);
        $roles = $reordered->map(fn (ServerRole $role) => new ServerRoleResource($role));

        return $this->successResponse('Порядок ролей обновлён', ['roles' => $roles]);
    }

    public function syncMemberRoles(AssignMemberRolesRequest $request, Server $server, ServerMember $member): JsonResponse
    {
        $this->authorize('manageRoles', $server);

        if ((int) $member->server_id !== (int) $server->id) {
            throw new ApiException('Участник не найден на этом сервере', 404);
        }

        $beforeIds = $member->roles()->pluck('server_roles.id')->all();
        $this->roles->syncMemberRoles($server, $this->roles->actorFor($server, $request->user()), $member, $request->validated('role_ids'));
        $afterIds = $member->roles()->pluck('server_roles.id')->all();
        $names = $server->roles()->whereIn('id', array_merge($beforeIds, $afterIds))->pluck('name', 'id');
        $added = array_values(array_diff($afterIds, $beforeIds));
        $removed = array_values(array_diff($beforeIds, $afterIds));
        if ($added !== [] || $removed !== []) {
            $member->loadMissing('user');
            $this->audit->record($server, $request->user(), 'member.roles', 'user', $member->user_id, $this->audit->userLabel($server, $member->user), [
                'added' => array_map(fn ($id) => $names[$id] ?? '?', $added),
                'removed' => array_map(fn ($id) => $names[$id] ?? '?', $removed),
            ]);
        }

        return $this->successResponse('Роли участника обновлены', [
            'member' => new ServerMemberResource($member->load('roles')),
        ]);
    }

    private function assertRoleBelongsToServer(Server $server, ServerRole $role): void
    {
        if ((int) $role->server_id !== (int) $server->id) {
            throw new ApiException('Роль не найдена на этом сервере', 404);
        }
    }
}
