<?php

namespace App\Data;

use App\Enums\MembershipStatus;

final readonly class ChannelCreator
{
    public function __construct(public int $id, public string $name, public MembershipStatus $status) {}

    /** @param array{id: int, name: string, status: MembershipStatus} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['name'], $data['status']);
    }

    /** @return array{id: int, name: string, status: MembershipStatus} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'status' => $this->status];
    }
}
