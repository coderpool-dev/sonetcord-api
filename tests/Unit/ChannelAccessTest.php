<?php

namespace Tests\Unit;

use App\Enums\ServerPermission;
use App\Models\Servers\ServerMember;
use App\Services\Servers\ChannelAccess;
use PHPUnit\Framework\TestCase;

class ChannelAccessTest extends TestCase
{
    public function test_owner_is_allowed_everything_even_without_membership(): void
    {
        $access = new ChannelAccess(isOwner: true, member: null, permissions: 0);

        $this->assertTrue($access->allows(ServerPermission::CONNECT_VOICE));
        $this->assertNull($access->denial(ServerPermission::SPEAK));
    }

    public function test_member_gets_effective_permissions_and_no_rights_message(): void
    {
        $access = new ChannelAccess(isOwner: false, member: new ServerMember, permissions: ServerPermission::CONNECT_VOICE);

        $this->assertTrue($access->allows(ServerPermission::CONNECT_VOICE));
        $this->assertFalse($access->allows(ServerPermission::SPEAK));
        $this->assertSame('Нет прав', $access->denial(ServerPermission::SPEAK));
    }

    public function test_stranger_gets_no_access_message(): void
    {
        $access = new ChannelAccess(isOwner: false, member: null, permissions: ServerPermission::ALL);

        $this->assertFalse($access->allows(ServerPermission::VIEW_CHANNELS));
        $this->assertSame('Нет доступа', $access->denial(ServerPermission::VIEW_CHANNELS));
    }
}
