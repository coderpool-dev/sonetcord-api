<?php

namespace App\Data;

use Illuminate\Http\UploadedFile;

final readonly class CreateServerData
{
    public function __construct(
        public string $name,
        public ?UploadedFile $icon = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            icon: ($data['icon'] ?? null),
        );
    }
}
