<?php

namespace App\Data;

final readonly class UploadFileData
{
    public function __construct(
        public string $filename,
        public int $size,
        public ?string $mime = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            filename: $data['filename'],
            size: (int) $data['size'],
            mime: ($data['mime'] ?? null),
        );
    }
}
