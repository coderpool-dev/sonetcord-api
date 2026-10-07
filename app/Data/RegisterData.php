<?php

namespace App\Data;

final readonly class RegisterData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $login,
        public string $password,
        public ?string $date = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            email: $data['email'],
            login: $data['login'],
            password: $data['password'],
            date: ($data['date'] ?? null),
        );
    }
}
