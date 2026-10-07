<?php

namespace App\Data;

final readonly class UpdateServerChannelData
{
    /**
     * @param  list<string>  $provided
     * @param  list<PermissionOverwriteData>|null  $overwrites
     * @param  list<PermissionOverwriteData>|null  $memberOverwrites
     */
    private function __construct(
        public ?string $name = null,
        public ?string $topic = null,
        public ?int $categoryId = null,
        public ?array $overwrites = null,
        public ?array $memberOverwrites = null,
        private array $provided = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: ($data['name'] ?? null),
            topic: ($data['topic'] ?? null),
            categoryId: (isset($data['category_id']) ? (int) $data['category_id'] : null),
            overwrites: isset($data['overwrites']) ? array_map(fn (array $row) => PermissionOverwriteData::fromArray($row, 'role_id'), $data['overwrites']) : null,
            memberOverwrites: isset($data['member_overwrites']) ? array_map(fn (array $row) => PermissionOverwriteData::fromArray($row, 'member_id'), $data['member_overwrites']) : null,
            provided: array_keys($data),
        );
    }

    public function has(string $field): bool
    {
        return in_array($field, $this->provided, true);
    }
}
