<?php

namespace App\Data;

final readonly class FeedbackData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $body,
        public ?string $page = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            email: $data['email'],
            body: $data['body'],
            page: ($data['page'] ?? null),
        );
    }
}
