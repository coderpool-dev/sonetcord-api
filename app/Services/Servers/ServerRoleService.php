<?php

namespace App\Services\Servers;

use App\Data\CreateServerRoleData;
use App\Data\UpdateServerRoleData;
use App\Enums\ServerPermission;
use App\Exceptions\ApiException;
use App\Models\Servers\Server;
use App\Models\Servers\ServerChannelRoleOverwrite;
use App\Models\Servers\ServerMember;
use App\Models\Servers\ServerRole;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Роли сервера, эффективные права участника и иерархия как в Discord: роль с большим
 * position стоит выше. Управлять можно только ролями и участниками строго ниже своей
 * высшей роли и выдавать только те права, что есть у тебя самого. Владелец — над всеми.
 * Само право на действие (MANAGE_ROLES и т.п.) проверяет ServerPolicy до вызова.
 */
class ServerRoleService
{
    public function listForServer(Server $server): Collection
    {
        return ServerRole::query()
            ->where('server_id', $server->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    public function actorFor(Server $server, User $user): ServerActor
    {
        if ((int) $server->owner_id === (int) $user->id) {
            return new ServerActor(true, ServerPermission::ALL, PHP_INT_MAX, null);
        }

        $member = ServerMember::query()
            ->where('server_id', $server->id)
            ->where('user_id', $user->id)
            ->active()
            ->with('roles')
            ->first();

        if (! $member) {
            return new ServerActor(false, 0, -1, null);
        }

        return new ServerActor(false, $this->basePermissions($member), $this->topPosition($member), $member);
    }

    /** Новая роль встаёт в самый низ, над @everyone (как в Discord), — не выше того, кто её создал. */
    public function create(Server $server, ServerActor $actor, CreateServerRoleData $validated): ServerRole
    {
        $permissions = $this->sanitizeMask($validated->permissions ?? 0);
        $this->assertCanGrant($actor, $permissions);

        return DB::transaction(function () use ($server, $validated, $permissions) {
            ServerRole::query()
                ->where('server_id', $server->id)
                ->where('is_default', false)
                ->increment('position');

            return ServerRole::create([
                'server_id' => $server->id,
                'name' => trim($validated->name),
                'color' => $validated->color ?? '#99aab5',
                'permissions' => $permissions,
                'position' => 1,
                'is_default' => false,
                'hoist' => (bool) ($validated->hoist ?? false),
                'mentionable' => (bool) ($validated->mentionable ?? false),
            ]);
        });
    }

    public function update(ServerRole $role, ServerActor $actor, UpdateServerRoleData $validated): ServerRole
    {
        $this->assertCanManageRole($actor, $role);

        if ($validated->has('name') && $validated->name !== null && ! $role->is_default) {
            $name = trim($validated->name);

            if ($name === '') {
                throw ValidationException::withMessages(['name' => ['Введите название роли']]);
            }

            $role->name = $name;
        }

        if ($validated->has('color') && $validated->color !== null) {
            $role->color = $validated->color;
        }

        if ($validated->has('hoist') && $validated->hoist !== null && ! $role->is_default) {
            $role->hoist = (bool) $validated->hoist;
        }

        if ($validated->has('mentionable') && $validated->mentionable !== null && ! $role->is_default) {
            $role->mentionable = (bool) $validated->mentionable;
        }

        if ($validated->has('permissions')) {
            $permissions = $this->sanitizeMask((int) $validated->permissions);
            // Проверяем только то, что реально меняется: чужие права, уже стоящие на роли,
            // не мешают поправить ей цвет или снять/добавить то, что есть у тебя.
            $this->assertCanGrant($actor, $permissions ^ $role->permissions);
            $role->permissions = $permissions;
        }

        $role->save();

        return $role->fresh();
    }

    public function delete(ServerRole $role, ServerActor $actor): void
    {
        if ($role->is_default) {
            throw new ApiException('Роль @everyone нельзя удалить', 422);
        }

        $this->assertCanManageRole($actor, $role);

        DB::transaction(function () use ($role) {
            $role->members()->detach();
            ServerChannelRoleOverwrite::query()->where('server_role_id', $role->id)->delete();
            $role->delete();
        });
    }

    /**
     * Новый порядок ролей (кроме @everyone), сверху вниз. Роли на уровне твоей высшей и выше
     * двигать нельзя — они обязаны остаться наверху в прежнем порядке, остальное — как угодно.
     *
     * @param  int[]  $roleIds
     */
    public function reorder(Server $server, ServerActor $actor, array $roleIds): Collection
    {
        $current = $this->listForServer($server)->where('is_default', false)->reverse()->values();
        $currentIds = $current->pluck('id')->map(fn ($id) => (int) $id)->all();
        $newIds = array_map('intval', $roleIds);

        $sortedCurrent = $currentIds;
        $sortedNew = $newIds;
        sort($sortedCurrent);
        sort($sortedNew);

        if ($sortedCurrent !== $sortedNew) {
            throw ValidationException::withMessages(['role_ids' => ['Список ролей устарел — обновите страницу']]);
        }

        if (! $actor->isOwner) {
            $lockedCount = $current->filter(fn (ServerRole $role) => $role->position >= $actor->topPosition)->count();

            if (array_slice($newIds, 0, $lockedCount) !== array_slice($currentIds, 0, $lockedCount)) {
                throw new ApiException('Нельзя двигать роли выше своей или ставить роли выше неё', 403);
            }
        }

        DB::transaction(function () use ($newIds) {
            $count = count($newIds);

            foreach ($newIds as $index => $roleId) {
                ServerRole::query()->whereKey($roleId)->update(['position' => $count - $index]);
            }
        });

        return $this->listForServer($server);
    }

    /** @param  int[]  $roleIds */
    public function syncMemberRoles(Server $server, ServerActor $actor, ServerMember $member, array $roleIds): void
    {
        $roles = $this->listForServer($server)->keyBy('id');

        $validIds = collect($roleIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $roles->has($id) && ! $roles[$id]->is_default)
            ->unique();

        $currentIds = $member->roles()->pluck('server_roles.id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $roles->has($id) && ! $roles[$id]->is_default);

        // Выдавать и снимать можно только роли ниже своей высшей — иначе модератор выдал бы себе
        // админскую роль или снял её с админа.
        $changed = $validIds->diff($currentIds)->merge($currentIds->diff($validIds));
        foreach ($changed as $roleId) {
            $this->assertCanManageRole($actor, $roles[$roleId]);
        }

        // Дефолтная роль выдаётся всем автоматически — снять её через этот эндпоинт нельзя.
        $defaultId = $roles->first(fn (ServerRole $role) => $role->is_default)?->id;
        $finalIds = $defaultId ? $validIds->push((int) $defaultId)->unique() : $validIds;

        $member->roles()->sync($finalIds->values()->all());
    }

    /** Побитовое ИЛИ прав всех ролей участника; «Администратор» даёт всё. Владелец обходит проверки в policy. */
    public function basePermissions(ServerMember $member): int
    {
        $mask = $member->roles->reduce(fn (int $mask, ServerRole $role) => $mask | $role->permissions, 0);

        return ServerPermission::has($mask, ServerPermission::ADMINISTRATOR) ? ServerPermission::ALL : $mask;
    }

    /** Позиция высшей роли участника; 0 — только @everyone. */
    public function topPosition(ServerMember $member): int
    {
        return (int) ($member->roles->max('position') ?? 0);
    }

    /** @everyone может править любой с MANAGE_ROLES; остальные роли — только строго ниже своей высшей. */
    public function assertCanManageRole(ServerActor $actor, ServerRole $role): void
    {
        if ($actor->isOwner || $role->is_default) {
            return;
        }

        if ($role->position >= $actor->topPosition) {
            throw new ApiException('Эта роль выше вашей или на одном уровне с ней', 403);
        }
    }

    /** Выдать или отнять можно только права, которые есть у тебя самого. */
    public function assertCanGrant(ServerActor $actor, int $changedBits): void
    {
        if ($actor->isOwner) {
            return;
        }

        if (($changedBits & ~$actor->permissions) !== 0) {
            throw new ApiException('Нельзя выдавать права, которых нет у вас самого', 403);
        }
    }

    /** Модерировать (кик, бан, мут, перемещение) можно только участника ниже себя по иерархии. */
    public function assertOutranks(ServerActor $actor, Server $server, ServerMember $target): void
    {
        // 422, как было у кика/бана владельца до иерархии, — клиенты уже на это рассчитывают.
        if ((int) $target->user_id === (int) $server->owner_id) {
            throw new ApiException('Нельзя применить это к владельцу сервера', 422);
        }

        if ($actor->isOwner) {
            return;
        }

        if ($this->topPosition($target->loadMissing('roles')) >= $actor->topPosition) {
            throw new ApiException('Роль участника выше вашей или на одном уровне с ней', 403);
        }
    }

    private function sanitizeMask(int $mask): int
    {
        return $mask & ServerPermission::ALL;
    }
}
