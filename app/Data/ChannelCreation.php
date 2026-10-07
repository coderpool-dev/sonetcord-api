<?php

namespace App\Data;

use App\Models\Conversations\Channel;
use App\Models\Conversations\ChannelMember;

final readonly class ChannelCreation
{
    /**
     * @param  list<int>  $recipients
     * @param  list<ChannelMember>  $members
     */
    public function __construct(
        public Channel $channel,
        public bool $created,
        public ?ChannelCreator $creator = null,
        public array $recipients = [],
        public array $members = [],
        public int $count = 0,
        public bool $isPrivate = false,
        public bool $rejoined = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if (! $this->created) {
            return ['channel' => $this->channel, 'existing' => true, 'rejoined' => $this->rejoined];
        }

        return ['channel' => $this->channel, 'creator' => $this->creator?->toArray(),
            'recipients' => $this->recipients, 'channel_members' => $this->members,
            'count' => $this->count, 'is_private' => $this->isPrivate];
    }
}
