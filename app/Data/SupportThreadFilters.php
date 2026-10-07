<?php

namespace App\Data;

final readonly class SupportThreadFilters
{
    public function __construct(
        public ?string $category = null,
        public ?string $unread = null,
        public ?string $sort = null,
        public ?string $order = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            category: ($data['category'] ?? null),
            unread: ($data['unread'] ?? null),
            sort: ($data['sort'] ?? null),
            order: ($data['order'] ?? null),
        );
    }
}
