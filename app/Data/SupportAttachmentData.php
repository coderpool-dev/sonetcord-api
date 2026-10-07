<?php

namespace App\Data;

final readonly class SupportAttachmentData
{
    public function __construct(
        public string $diskPath,
        public string $mime,
        public string $name,
        public string $kind,
        public int $size,
        public ?int $width = null,
        public ?int $height = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            diskPath: $data['disk_path'],
            mime: $data['mime'],
            name: $data['name'],
            kind: $data['kind'],
            size: (int) $data['size'],
            width: (isset($data['width']) ? (int) $data['width'] : null),
            height: (isset($data['height']) ? (int) $data['height'] : null),
        );
    }

    /** @return array{disk_path: string, mime: string, name: string, kind: string, size: int, width: int|null, height: int|null} */
    public function toArray(): array
    {
        return ['disk_path' => $this->diskPath, 'mime' => $this->mime, 'name' => $this->name,
            'kind' => $this->kind, 'size' => $this->size, 'width' => $this->width, 'height' => $this->height];
    }
}
