<?php

namespace App\Services\Servers;

use App\Models\Servers\ServerChannel;
use App\Models\User;

/**
 * Единственный источник прав в канале сервера: им пользуются ServerChannelPolicy и сервисы.
 * Результат запоминается на время запроса (сервис зарегистрирован как scoped): heartbeat
 * голосового канала проверяет доступ в политике и тут же строит ограничения голоса —
 * без запоминания права считались дважды.
 */
class ServerChannelAccess
{
    /** @var array<string, ChannelAccess> */
    private array $resolved = [];

    public function __construct(
        private readonly ServerService $servers,
        private readonly ServerChannelPermissionResolver $resolver,
    ) {}

    public function for(User $user, ServerChannel $channel): ChannelAccess
    {
        return $this->resolved[$user->id.':'.$channel->id] ??= $this->resolve($user, $channel);
    }

    private function resolve(User $user, ServerChannel $channel): ChannelAccess
    {
        $isOwner = $this->servers->isOwner((int) $user->id, (int) $channel->server_id);
        $member = $this->servers->activeMembershipWithRoles((int) $user->id, (int) $channel->server_id);

        return new ChannelAccess(
            isOwner: $isOwner,
            member: $member,
            permissions: $member !== null ? $this->resolver->effectivePermissions($member, $channel) : 0,
        );
    }
}
