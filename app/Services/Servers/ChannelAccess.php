<?php

namespace App\Services\Servers;

use App\Enums\ServerPermission;
use App\Models\Servers\ServerMember;

/** Доступ пользователя к каналу сервера: владелец, участие и итоговые права в канале. */
final readonly class ChannelAccess
{
    public function __construct(
        public bool $isOwner,
        public ?ServerMember $member,
        public int $permissions,
    ) {}

    /** Владелец сервера может всё; остальные — по итоговым правам канала. */
    public function allows(int $permission): bool
    {
        return $this->isOwner || ($this->member !== null && ServerPermission::has($this->permissions, $permission));
    }

    /** Текст отказа для политики, если права $permission нет; null — доступ есть. */
    public function denial(int $permission): ?string
    {
        if ($this->allows($permission)) {
            return null;
        }

        return $this->member === null ? 'Нет доступа' : 'Нет прав';
    }
}
