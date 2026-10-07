<?php

namespace App\Data;

use Illuminate\Http\UploadedFile;

final readonly class CreateChannelData
{
    /** @param list<int> $recipients */
    public function __construct(
        public array $recipients,
        public ?string $name = null,
        public ?int $status = null,
        public ?UploadedFile $avatar = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            recipients: array_values(array_map('intval', $data['recipients'])),
            name: ($data['name'] ?? null),
            status: (isset($data['status']) ? (int) $data['status'] : null),
            avatar: ($data['avatar'] ?? null),
        );
    }
}
