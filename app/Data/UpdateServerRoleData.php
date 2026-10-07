<?php

namespace App\Data;

final readonly class UpdateServerRoleData
{
    /** @param list<string> $provided */
    private function __construct(
        public ?string $name = null,
        public ?string $color = null,
        public ?int $permissions = null,
        public ?bool $hoist = null,
        public ?bool $mentionable = null,
        private array $provided = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: ($data['name'] ?? null),
            color: ($data['color'] ?? null),
            permissions: (isset($data['permissions']) ? (int) $data['permissions'] : null),
            hoist: (isset($data['hoist']) ? (bool) $data['hoist'] : null),
            mentionable: (isset($data['mentionable']) ? (bool) $data['mentionable'] : null),
            provided: array_keys($data),
        );
    }

    public function has(string $field): bool
    {
        return in_array($field, $this->provided, true);
    }
}
