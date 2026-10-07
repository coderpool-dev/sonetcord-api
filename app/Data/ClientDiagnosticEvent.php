<?php

namespace App\Data;

final readonly class ClientDiagnosticEvent
{
    /** @param array<string, mixed> $data Validated event-specific telemetry. */
    public function __construct(
        public string $event,
        public string $level,
        public string $at,
        public string $page,
        public bool $online,
        public string $visibility,
        public int $repeat,
        public array $data,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            event: $data['event'],
            level: $data['level'],
            at: $data['at'],
            page: $data['page'],
            online: (bool) $data['online'],
            visibility: $data['visibility'],
            repeat: (int) $data['repeat'],
            data: $data['data'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['event' => $this->event, 'level' => $this->level, 'at' => $this->at,
            'page' => $this->page, 'online' => $this->online, 'visibility' => $this->visibility,
            'repeat' => $this->repeat, 'data' => $this->data];
    }
}
