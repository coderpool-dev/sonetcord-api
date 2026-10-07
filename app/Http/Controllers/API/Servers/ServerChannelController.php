<?php

namespace App\Http\Controllers\API\Servers;

use App\Data\CreateServerChannelData;
use App\Data\UpdateServerChannelData;
use App\Enums\ServerChannelKind;
use App\Enums\ServerPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Servers\StoreServerChannelRequest;
use App\Http\Requests\Servers\UpdateServerChannelRequest;
use App\Http\Resources\ServerChannelResource;
use App\Models\Servers\Server;
use App\Models\Servers\ServerChannel;
use App\Services\Servers\ServerAuditLogService;
use App\Services\Servers\ServerChannelCallService;
use App\Services\Servers\ServerChannelPermissionResolver;
use App\Services\Servers\ServerChannelReadService;
use App\Services\Servers\ServerChannelService;
use App\Services\Servers\ServerRoleService;
use App\Services\Servers\ServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerChannelController extends Controller
{
    public function __construct(
        private readonly ServerChannelService $channels,
        private readonly ServerChannelCallService $calls,
        private readonly ServerService $servers,
        private readonly ServerChannelPermissionResolver $resolver,
        private readonly ServerRoleService $roles,
        private readonly ServerAuditLogService $audit,
        private readonly ServerChannelReadService $reads,
    ) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->authorize('view', $server);

        $user = $request->user();
        $isOwner = $this->servers->isOwner((int) $user->id, (int) $server->id);
        $member = $isOwner ? null : $this->servers->activeMembershipWithRoles((int) $user->id, (int) $server->id);

        $visible = $this->channels->listForServer($server, $user);
        $readState = $this->reads->stateFor($user, $visible);

        $channels = $visible
            ->map(function (ServerChannel $channel) use ($isOwner, $member, $readState) {
                if (isset($readState[$channel->id])) {
                    $channel->last_message_id = $readState[$channel->id]['last_message_id'];
                    $channel->last_read_message_id = $readState[$channel->id]['last_read_message_id'];
                }

                if ($channel->kind === ServerChannelKind::Voice) {
                    $channel->active_participants = $this->calls->participants($channel);
                }

                $channel->my_permissions = $isOwner
                    ? ServerPermission::ALL
                    : ($member ? $this->resolver->effectivePermissions($member, $channel) : 0);

                return new ServerChannelResource($channel->loadMissing(['roleOverwrites', 'memberOverwrites']));
            });

        return $this->successResponse('Список каналов успешно получен', ['channels' => $channels]);
    }

    public function store(StoreServerChannelRequest $request, Server $server): JsonResponse
    {
        $this->authorize('manageChannels', $server);

        $channel = $this->channels->create($server, CreateServerChannelData::fromArray($request->validated()));
        $this->audit->record($server, $request->user(), 'channel.create', 'channel', $channel->id, $channel->name, [
            'kind' => ['new' => $channel->kind->value],
        ]);

        return $this->successResponse('Канал создан', ['channel' => new ServerChannelResource($channel)], 201);
    }

    public function update(UpdateServerChannelRequest $request, Server $server, ServerChannel $serverChannel): JsonResponse
    {
        $this->authorize('manageChannels', $server);

        // Права канала — это «Управлять правами» из Discord: нужен ещё MANAGE_ROLES,
        // иначе управляющий каналами открыл бы себе любой закрытый канал.
        if ($request->has('overwrites') || $request->has('member_overwrites')) {
            $this->authorize('manageRoles', $server);
        }

        $before = $serverChannel->only(['name', 'topic', 'category_id']);
        $overwritesBefore = $this->permissionOverwritesSnapshot($serverChannel);
        $updated = $this->channels->update($serverChannel, $this->roles->actorFor($server, $request->user()), UpdateServerChannelData::fromArray($request->validated()));

        $changes = collect($updated->only(array_keys($before)))
            ->filter(fn ($value, $key) => $value != $before[$key])
            ->map(fn ($value, $key) => ['old' => $before[$key], 'new' => $value])
            ->all();
        if ($changes !== []) {
            $this->audit->record($server, $request->user(), 'channel.update', 'channel', $updated->id, $updated->name, $changes);
        }
        $overwritesAfter = $this->permissionOverwritesSnapshot($updated);
        if ($overwritesAfter !== $overwritesBefore) {
            $this->audit->record($server, $request->user(), 'channel.permissions', 'channel', $updated->id, $updated->name, [
                'overwrites' => ['old' => $overwritesBefore, 'new' => $overwritesAfter],
            ]);
        }

        return $this->successResponse('Канал обновлён', ['channel' => new ServerChannelResource($updated->load(['roleOverwrites', 'memberOverwrites']))]);
    }

    public function destroy(Server $server, ServerChannel $serverChannel): JsonResponse
    {
        $this->authorize('manageChannels', $server);

        $this->channels->delete($serverChannel);
        $this->audit->record($server, request()->user(), 'channel.delete', 'channel', $serverChannel->id, $serverChannel->name);

        return $this->successResponse('Канал удалён');
    }

    /**
     * Права канала читаемо для журнала: «роль/участник → allow/deny», с именами на момент изменения.
     *
     * @return array<string, array{allow: int, deny: int}>
     */
    private function permissionOverwritesSnapshot(ServerChannel $channel): array
    {
        $snapshot = [];
        foreach ($channel->roleOverwrites()->with('role:id,name,is_default')->get() as $overwrite) {
            $name = $overwrite->role?->is_default ? '@everyone' : ($overwrite->role->name ?? "роль #{$overwrite->server_role_id}");
            $snapshot["role:{$name}"] = ['allow' => (int) $overwrite->allow, 'deny' => (int) $overwrite->deny];
        }
        foreach ($channel->memberOverwrites()->with('member.user:id,name,login')->get() as $overwrite) {
            $name = $overwrite->member?->nickname ?: ($overwrite->member?->user->name ?? "участник #{$overwrite->server_member_id}");
            $snapshot["member:{$name}"] = ['allow' => (int) $overwrite->allow, 'deny' => (int) $overwrite->deny];
        }
        ksort($snapshot);

        return $snapshot;
    }
}
