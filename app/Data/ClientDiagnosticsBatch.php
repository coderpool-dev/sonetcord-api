<?php

namespace App\Data;

final readonly class ClientDiagnosticsBatch
{
    /** @param list<ClientDiagnosticEvent> $events */
    public function __construct(
        public string $sessionId,
        public string $batchId,
        public string $platform,
        public string $build,
        public array $events,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            sessionId: $data['session_id'],
            batchId: $data['batch_id'],
            platform: $data['platform'],
            build: $data['build'],
            events: array_map(ClientDiagnosticEvent::fromArray(...), $data['events']),
        );
    }
}
