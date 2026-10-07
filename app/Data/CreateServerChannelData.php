<?php

namespace App\Data;

final readonly class CreateServerChannelData
{
    public function __construct(
        public string $name,
        public int $kind,
        public ?string $topic = null,
        public ?int $categoryId = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            kind: (int) $data['kind'],
            topic: ($data['topic'] ?? null),
            categoryId: (isset($data['category_id']) ? (int) $data['category_id'] : null),
        );
    }
}
