<?php

namespace App\Data;

final readonly class PermissionOverwriteData
{
    public function __construct(public int $targetId, public int $allow = 0, public int $deny = 0) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $targetKey): self
    {
        return new self((int) $data[$targetKey], (int) ($data['allow'] ?? 0), (int) ($data['deny'] ?? 0));
    }
}
