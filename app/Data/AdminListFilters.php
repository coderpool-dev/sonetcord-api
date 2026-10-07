<?php

namespace App\Data;

final readonly class AdminListFilters
{
    public function __construct(
        public ?string $status = null,
        public ?string $q = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            status: ($data['status'] ?? null),
            q: ($data['q'] ?? null),
        );
    }
}
