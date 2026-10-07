<?php

namespace App\Services\Servers;

use App\Enums\ServerChannelKind;
use App\Enums\ServerMembershipStatus;
use App\Enums\ServerPermission;
use App\Exceptions\ApiException;
use App\Models\Conversations\Call;
use App\Models\Conversations\Message;
use App\Models\Servers\Server;
use App\Models\Servers\ServerChannel;
use App\Models\Servers\ServerMember;
use App\Models\Servers\ServerRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Серверы: создание, список, обновление. Права на действие проверяет ServerPolicy,
 * здесь — только правила самого сервера.
 */
class ServerService
{
    public function create(User $user, array $validated): Server
    {
        return DB::transaction(function () use ($user, $validated) {
            $server = Server::create([
                'name' => trim($validated['name']),
                'owner_id' => $user->id,
            ]);

            if (! empty($validated['icon'])) {
                $server->icon = $this->storeImage($validated['icon'], 'server-icons', 'server_'.$server->id);
                $server->save();
            }

            // Роль @everyone — базовые права, есть у всех участников автоматически,
            // неудаляемая (see ServerRoleService::delete в Фазе 4).
            $defaultRole = ServerRole::create([
                'server_id' => $server->id,
                'name' => 'everyone',
                'permissions' => ServerPermission::DEFAULT,
                'is_default' => true,
            ]);

            $member = ServerMember::create([
                'server_id' => $server->id,
                'user_id' => $user->id,
                'status' => ServerMembershipStatus::Member,
                'joined_at' => now(),
            ]);
            $member->roles()->attach($defaultRole->id);

            ServerChannel::create([
                'server_id' => $server->id,
                'name' => 'general',
                'kind' => ServerChannelKind::Text,
            ]);

            return $server;
        });
    }

    /**
     * Серверы, в которых пользователь состоит активным участником.
     *
     * @return Collection<int, Server>
     */
    public function serversFor(User $user): Collection
    {
        return Server::query()
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id)->active())
            ->orderBy('name')
            ->get();
    }

    public function update(
        int $serverId,
        ?string $name = null,
        ?UploadedFile $iconFile = null,
        bool $removeIcon = false,
    ): Server {
        $server = Server::query()->findOrFail($serverId);

        if ($name !== null) {
            $name = trim($name);

            if ($name === '') {
                throw ValidationException::withMessages(['name' => ['Введите название сервера']]);
            }

            $server->name = $name;
        }

        if (($removeIcon || $iconFile) && $server->icon) {
            // Иконка demo_* общая для всех демо-серверов — гость меняет только свою ссылку на неё.
            if (! str_starts_with((string) $server->icon, 'demo_')) {
                $this->deleteImage('server-icons', (string) $server->icon);
            }
            $server->icon = null;
        }

        if ($iconFile) {
            $server->icon = $this->storeImage($iconFile, 'server-icons', 'server_'.$server->id);
        }

        $server->save();

        return $server->fresh();
    }

    /**
     * Нет каскадных FK на server_id (см. соглашение репозитория — явная очистка,
     * а не ON DELETE CASCADE), поэтому дочерние строки чистим сами перед сервером.
     */
    public function delete(int $serverId): void
    {
        DB::transaction(function () use ($serverId) {
            $server = Server::query()->findOrFail($serverId);
            $channelIds = ServerChannel::query()->where('server_id', $serverId)->pluck('id');

            // Звонки голосовых каналов ссылаются на server_channels.
            $callIds = Call::query()->whereIn('server_channel_id', $channelIds)->pluck('call_id');
            DB::table('call_sessions')->whereIn('call_id', $callIds)->delete();
            DB::table('call_sessions')->whereIn('server_channel_id', $channelIds)->delete();
            Call::query()->whereIn('server_channel_id', $channelIds)->delete();

            // MySQL проверяет внешний ключ построчно: категория, удалённая раньше своих каналов,
            // уронила бы весь DELETE. Поэтому сначала отвязываем каналы от категорий.
            ServerChannel::query()->where('server_id', $serverId)->whereNotNull('category_id')->update(['category_id' => null]);

            DB::table('server_member_role')
                ->whereIn('server_member_id', ServerMember::query()->where('server_id', $serverId)->pluck('id'))
                ->delete();

            DB::table('server_channel_role_overwrites')
                ->whereIn('server_channel_id', ServerChannel::query()->where('server_id', $serverId)->pluck('id'))
                ->delete();

            DB::table('server_audit_logs')->where('server_id', $serverId)->delete();

            DB::table('server_channel_member_overwrites')
                ->whereIn('server_channel_id', ServerChannel::query()->where('server_id', $serverId)->pluck('id'))
                ->delete();

            Message::query()
                ->whereIn('server_channel_id', ServerChannel::query()->where('server_id', $serverId)->pluck('id'))
                ->delete();

            $server->invites()->delete();
            $server->channels()->delete();
            $server->roles()->delete();
            $server->members()->delete();
            $server->bans()->delete();
            $server->delete();
        });
    }

    public function leave(User $user, int $serverId): void
    {
        $server = Server::query()->findOrFail($serverId);

        if ((int) $server->owner_id === (int) $user->id) {
            throw new ApiException('Владелец не может покинуть сервер, только удалить его', 422);
        }

        $membership = $this->activeMembership($user->id, $serverId)
            ?? throw new ApiException('Вы не состоите на этом сервере', 403);

        $membership->update(['status' => ServerMembershipStatus::Removed]);
    }

    public function isMember(int $userId, int $serverId): bool
    {
        return $this->activeMembership($userId, $serverId) !== null;
    }

    public function isOwner(int $userId, int $serverId): bool
    {
        return Server::query()->whereKey($serverId)->value('owner_id') == $userId;
    }

    /** Как activeMembership, но с подгруженными ролями — для подсчёта эффективных прав. */
    public function activeMembershipWithRoles(int $userId, int $serverId): ?ServerMember
    {
        return ServerMember::query()
            ->where('server_id', $serverId)
            ->where('user_id', $userId)
            ->active()
            ->with('roles')
            ->first();
    }

    private function activeMembership(int $userId, int $serverId): ?ServerMember
    {
        return ServerMember::query()
            ->where('server_id', $serverId)
            ->where('user_id', $userId)
            ->active()
            ->first();
    }

    private function storeImage(UploadedFile $file, string $disk, string $prefix): string
    {
        $fileName = $prefix.'_'.Str::uuid().'.'.($file->getClientOriginalExtension() ?: 'jpg');
        $file->storeAs($disk, $fileName, 'public');

        return $fileName;
    }

    private function deleteImage(string $disk, string $fileName): void
    {
        if ($fileName === '') {
            return;
        }

        Storage::disk('public')->delete($disk.'/'.basename($fileName));
    }
}
