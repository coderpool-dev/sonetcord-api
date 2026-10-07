<?php

namespace App\Data;

final readonly class PushNotificationData
{
    /**
     * @param  list<string>  $provided
     * @param  list<int>|null  $silentUserIds
     */
    private function __construct(
        public string $title,
        public string $body,
        public string $url,
        public string $tag,
        public ?string $icon = null,
        public ?string $kind = null,
        public ?string $callId = null,
        public ?int $channelId = null,
        public ?int $initiatorId = null,
        public ?string $initiatorLogin = null,
        public ?string $initiatorAvatar = null,
        public ?array $silentUserIds = null,
        private array $provided = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'],
            body: $data['body'],
            url: $data['url'],
            tag: $data['tag'],
            icon: ($data['icon'] ?? null),
            kind: ($data['kind'] ?? null),
            callId: ($data['call_id'] ?? null),
            channelId: (isset($data['channel_id']) ? (int) $data['channel_id'] : null),
            initiatorId: (isset($data['initiator_id']) ? (int) $data['initiator_id'] : null),
            initiatorLogin: ($data['initiator_login'] ?? null),
            initiatorAvatar: ($data['initiator_avatar'] ?? null),
            silentUserIds: ($data['silent_user_ids'] ?? null),
            provided: array_keys($data),
        );
    }

    public function has(string $field): bool
    {
        return in_array($field, $this->provided, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'tag' => $this->tag,
            'icon' => $this->icon,
            'kind' => $this->kind,
            'call_id' => $this->callId,
            'channel_id' => $this->channelId,
            'initiator_id' => $this->initiatorId,
            'initiator_login' => $this->initiatorLogin,
            'initiator_avatar' => $this->initiatorAvatar,
            'silent_user_ids' => $this->silentUserIds,
        ];

        return array_filter($data, fn ($value, string $key) => $value !== null || $this->has($key), ARRAY_FILTER_USE_BOTH);
    }
}
