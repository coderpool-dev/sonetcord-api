<?php

namespace App\Data;

final readonly class UserReportData
{
    public function __construct(
        public int $targetId,
        public string $reason,
        public ?string $comment = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            targetId: (int) $data['target_id'],
            reason: $data['reason'],
            comment: ($data['comment'] ?? null),
        );
    }
}
