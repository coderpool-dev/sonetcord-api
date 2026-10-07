<?php

namespace App\Data;

final readonly class CreateServerRoleData
{
    public function __construct(
        public string $name,
        public ?string $color = null,
        public ?int $permissions = null,
        public ?bool $hoist = null,
        public ?bool $mentionable = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            color: ($data['color'] ?? null),
            permissions: (isset($data['permissions']) ? (int) $data['permissions'] : null),
            hoist: (isset($data['hoist']) ? (bool) $data['hoist'] : null),
            mentionable: (isset($data['mentionable']) ? (bool) $data['mentionable'] : null),
        );
    }
}
