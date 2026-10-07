<?php

namespace App\Data;

final readonly class AttachmentResult
{
    public function __construct(
        public string $diskPath,
        public string $name,
        public string $mime,
        public int $size,
        public string $kind,
        public bool $encrypted,
        public int $id,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $keyId = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            diskPath: $data['disk_path'],
            name: $data['name'],
            mime: $data['mime'],
            size: (int) $data['size'],
            kind: $data['kind'],
            encrypted: (bool) $data['encrypted'],
            id: (int) $data['id'],
            width: (isset($data['width']) ? (int) $data['width'] : null),
            height: (isset($data['height']) ? (int) $data['height'] : null),
            keyId: (isset($data['key_id']) ? (int) $data['key_id'] : null),
        );
    }

    /** @return array{disk_path: string, name: string, mime: string, size: int, kind: string, encrypted: bool, id: int, width: int|null, height: int|null, key_id: int|null} */
    public function toArray(): array
    {
        return [
            'disk_path' => $this->diskPath, 'name' => $this->name, 'mime' => $this->mime,
            'size' => $this->size, 'kind' => $this->kind, 'encrypted' => $this->encrypted,
            'id' => $this->id, 'width' => $this->width, 'height' => $this->height, 'key_id' => $this->keyId,
        ];
    }
}
