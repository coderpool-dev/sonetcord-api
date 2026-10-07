<?php

namespace App\Data;

final readonly class UpdateProfileData
{
    /** @param list<string> $provided */
    private function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?string $date = null,
        public ?string $currentPassword = null,
        public ?string $newPassword = null,
        public ?string $bannerColor = null,
        public ?string $presence = null,
        public ?string $statusEmoji = null,
        public ?string $statusText = null,
        public ?string $gameStatusText = null,
        public ?bool $removeBanner = null,
        public ?bool $clearStatus = null,
        public ?bool $clearGameStatus = null,
        private array $provided = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: ($data['name'] ?? null),
            email: ($data['email'] ?? null),
            date: ($data['date'] ?? null),
            currentPassword: ($data['current_password'] ?? null),
            newPassword: ($data['new_password'] ?? null),
            bannerColor: ($data['banner_color'] ?? null),
            presence: ($data['presence'] ?? null),
            statusEmoji: ($data['status_emoji'] ?? null),
            statusText: ($data['status_text'] ?? null),
            gameStatusText: ($data['game_status_text'] ?? null),
            removeBanner: (isset($data['remove_banner']) ? (bool) $data['remove_banner'] : null),
            clearStatus: (isset($data['clear_status']) ? (bool) $data['clear_status'] : null),
            clearGameStatus: (isset($data['clear_game_status']) ? (bool) $data['clear_game_status'] : null),
            provided: array_keys($data),
        );
    }

    public function has(string $field): bool
    {
        return in_array($field, $this->provided, true);
    }
}
