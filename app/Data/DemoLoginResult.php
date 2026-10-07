<?php

namespace App\Data;

use App\Models\User;

final readonly class DemoLoginResult
{
    public function __construct(
        public User $user,
        public string $token,
        public int $serverId,
        public int $channelId,
    ) {}
}
