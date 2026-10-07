<?php

namespace App\Data;

final readonly class LoginData
{
    public function __construct(
        public string $email,
        public string $password,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            email: $data['email'],
            password: $data['password'],
        );
    }
}
