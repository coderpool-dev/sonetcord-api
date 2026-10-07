<?php

namespace App\Data;

final readonly class GeoLocationData
{
    public function __construct(
        public string $country,
        public ?string $countryCode = null,
        public ?string $city = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            country: $data['country'],
            countryCode: ($data['country_code'] ?? null),
            city: ($data['city'] ?? null),
        );
    }
}
