<?php

namespace App\Data;

final readonly class ClientLogFilters
{
    public function __construct(
        public ?string $date = null,
        public ?int $userId = null,
        public ?string $sessionId = null,
        public ?string $level = null,
        public ?string $event = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            date: ($data['date'] ?? null),
            userId: (isset($data['user_id']) ? (int) $data['user_id'] : null),
            sessionId: ($data['session_id'] ?? null),
            level: ($data['level'] ?? null),
            event: ($data['event'] ?? null),
        );
    }
}
