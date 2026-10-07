<?php

namespace App\Services\Servers;

use App\Data\CreateServerInviteData;
use App\Enums\ServerMembershipStatus;
use App\Exceptions\ApiException;
use App\Models\Servers\Server;
use App\Models\Servers\ServerBan;
use App\Models\Servers\ServerChannel;
use App\Models\Servers\ServerInvite;
use App\Models\Servers\ServerMember;
use App\Models\Servers\ServerRole;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Ссылки-приглашения на сервер: создание, вступление, отзыв. */
class ServerInviteService
{
    private const CODE_LENGTH = 8;

    public function listForServer(Server $server): Collection
    {
        return ServerInvite::query()
            ->where('server_id', $server->id)
            ->with(['creator:id,name,login,avatar,updated_at'])
            ->latest()
            ->get();
    }

    public function create(Server $server, User $creator, CreateServerInviteData $validated): ServerInvite
    {
        if (! empty($validated->channelId)) {
            $exists = ServerChannel::query()
                ->whereKey($validated->channelId)
                ->where('server_id', $server->id)
                ->exists();

            if (! $exists) {
                throw new ApiException('Канал не найден на этом сервере', 404);
            }
        }

        return ServerInvite::create([
            'code' => $this->generateUniqueCode(),
            'server_id' => $server->id,
            'channel_id' => $validated->channelId ?? null,
            'created_by' => $creator->id,
            'max_uses' => $validated->maxUses ?? null,
            'expires_at' => $validated->expiresAt ?? null,
        ]);
    }

    public function revoke(ServerInvite $invite): void
    {
        $invite->update(['revoked_at' => now()]);
    }

    /**
     * Лёгкое превью для публичной (без логина) страницы приглашения — без мутаций.
     *
     * @return array{usable: bool, server: array{name: string, icon: ?string, members_count: int}|null}
     */
    public function preview(string $code): array
    {
        $invite = ServerInvite::query()->where('code', $code)->with('server.members')->first();

        if (! $invite || ! ServerInvite::query()->usable()->whereKey($invite->id)->exists()) {
            return ['usable' => false, 'server' => null];
        }

        $server = $invite->server;

        return [
            'usable' => true,
            'server' => [
                'name' => $server->name,
                'icon' => $server->icon ? Storage::disk('public')->url('server-icons/'.$server->icon) : null,
                'members_count' => $server->members->count(),
            ],
        ];
    }

    /** Вступает пользователь в сервер по коду приглашения. Возвращает сервер, в который вступили. */
    public function join(User $user, string $code): Server
    {
        return DB::transaction(function () use ($user, $code) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $invite = ServerInvite::query()->usable()->where('code', $code)->lockForUpdate()->first()
                ?? throw new ApiException('Приглашение недействительно или истекло', 404);

            $server = $invite->server;

            $isBanned = ServerBan::query()
                ->where('server_id', $server->id)
                ->where('user_id', $user->id)
                ->exists();

            if ($isBanned) {
                throw new ApiException('Вы забанены на этом сервере', 403);
            }

            $membership = ServerMember::query()
                ->where('server_id', $server->id)
                ->where('user_id', $user->id)
                ->first();

            if ($membership && $membership->status !== ServerMembershipStatus::Removed) {
                return $server;
            }

            if ($membership) {
                $membership->update(['status' => ServerMembershipStatus::Member, 'joined_at' => now()]);
            } else {
                $membership = ServerMember::create([
                    'server_id' => $server->id,
                    'user_id' => $user->id,
                    'status' => ServerMembershipStatus::Member,
                    'joined_at' => now(),
                ]);
            }

            // Без этого у только что вступившего ноль прав (VIEW_CHANNELS в том числе) —
            // сервер выглядел бы вступившему пустым.
            $defaultRoleId = ServerRole::query()->where('server_id', $server->id)->where('is_default', true)->value('id');
            if ($defaultRoleId) {
                $membership->roles()->syncWithoutDetaching([$defaultRoleId]);
            }

            $invite->increment('uses');

            return $server;
        }, 3);
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = Str::random(self::CODE_LENGTH);
        } while (ServerInvite::query()->where('code', $code)->exists());

        return $code;
    }
}
