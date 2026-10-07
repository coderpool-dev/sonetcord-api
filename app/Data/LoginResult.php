<?php

namespace App\Data;

use App\Models\User;

final readonly class LoginResult
{
    public function __construct(
        public User $user,
        public string $token,
    ) {}
}
