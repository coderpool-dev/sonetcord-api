<?php

namespace App\Data;

final readonly class CreateServerInviteData
{
    public function __construct(
        public ?int $channelId = null,
        public ?int $maxUses = null,
        public ?string $expiresAt = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            channelId: (isset($data['channel_id']) ? (int) $data['channel_id'] : null),
            maxUses: (isset($data['max_uses']) ? (int) $data['max_uses'] : null),
            expiresAt: ($data['expires_at'] ?? null),
        );
    }
}
