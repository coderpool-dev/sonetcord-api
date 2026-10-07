<?php

namespace App\Data;

use App\Models\User;

final readonly class ProfileUpdateResult
{
    public function __construct(
        public User $user,
        public bool $changed,
    ) {}
}
