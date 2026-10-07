<?php

namespace App\Data;

final readonly class NetworkLatencySample
{
    public function __construct(
        public string $metric,
        public bool $ok,
        public ?float $rttMs = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            metric: $data['metric'],
            ok: (bool) $data['ok'],
            rttMs: (isset($data['rtt_ms']) ? (float) $data['rtt_ms'] : null),
        );
    }
}
