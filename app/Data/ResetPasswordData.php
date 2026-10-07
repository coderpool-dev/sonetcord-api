<?php

namespace App\Data;

final readonly class ResetPasswordData
{
    public function __construct(
        public string $email,
        public string $token,
        public string $password,
        public ?string $passwordConfirmation = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            email: $data['email'],
            token: $data['token'],
            password: $data['password'],
            passwordConfirmation: ($data['password_confirmation'] ?? null),
        );
    }
}
