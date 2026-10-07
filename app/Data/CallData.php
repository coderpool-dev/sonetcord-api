<?php

namespace App\Data;

final readonly class CallData
{
    /** @param list<int>|null $silentUserIds */
    public function __construct(
        public string $callId,
        public int $channelId,
        public int $initiatorId,
        public string $initiatorLogin,
        public string $status,
        public string $type,
        public ?array $silentUserIds = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            callId: $data['call_id'],
            channelId: (int) $data['channel_id'],
            initiatorId: (int) $data['initiator_id'],
            initiatorLogin: $data['initiator_login'],
            status: $data['status'],
            type: $data['type'],
            silentUserIds: $data['silent_user_ids'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['call_id' => $this->callId, 'channel_id' => $this->channelId,
            'initiator_id' => $this->initiatorId, 'initiator_login' => $this->initiatorLogin,
            'status' => $this->status, 'type' => $this->type];

        if ($this->silentUserIds !== null) {
            $data['silent_user_ids'] = $this->silentUserIds;
        }

        return $data;
    }
}
