<?php

namespace Tests\Feature;

use App\Data\PushNotificationData;
use App\Enums\ChannelType;
use App\Enums\ServerChannelKind;
use App\Models\Servers\ServerChannel;
use App\Services\Account\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithCalls;
use Tests\Concerns\InteractsWithServers;
use Tests\TestCase;

/** Непрочитанное в каналах серверов и «Заглушить» для чатов/каналов/серверов. */
class ServerUnreadAndMuteTest extends TestCase
{
    use InteractsWithCalls, InteractsWithServers, RefreshDatabase;

    /** @var array<int, array{users: int[], payload: array}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $sent = &$this->sent;
        $this->app->instance(PushNotificationService::class, new class($sent) extends PushNotificationService
        {
            public function __construct(private array &$sink) {}

            public function sendToUsers(array $userIds, PushNotificationData $payload): void
            {
                $this->sink[] = ['users' => array_values($userIds), 'payload' => $payload->toArray()];
            }
        });
    }

    public function test_channel_becomes_unread_after_someone_else_writes_and_read_clears_it(): void
    {
        $owner = $this->makeUser();
        $bob = $this->makeUser();
        $server = $this->makeServer($owner);
        $this->addServerMember($server, $owner);
        $this->addServerMember($server, $bob);
        $channel = $this->makeServerChannel($server, ['kind' => ServerChannelKind::Text]);

        Sanctum::actingAs($bob, ['*']);
        $this->postJson("/api/server-channel-messages/{$channel->id}/read")->assertOk();

        // Своё сообщение канал непрочитанным не делает.
        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/server-channel-messages/{$channel->id}/read")->assertOk();
        $this->postJson('/api/server-channel-messages', ['server_channel_id' => $channel->id, 'message' => 'привет'])->assertCreated();
        $this->assertFalse($this->getJson('/api/servers')->assertOk()->json('servers.0.has_unread'));

        Sanctum::actingAs($bob, ['*']);
        $servers = $this->getJson('/api/servers')->assertOk();
        $this->assertTrue($servers->json('servers.0.has_unread'));
        $row = collect($this->getJson("/api/servers/{$server->id}/channels")->json('channels'))->firstWhere('id', $channel->id);
        $this->assertGreaterThan($row['last_read_message_id'], $row['last_message_id']);

        $this->postJson("/api/servers/{$server->id}/read")->assertOk();
        $this->assertFalse($this->getJson('/api/servers')->json('servers.0.has_unread'));
    }

    public function test_read_marker_never_moves_backwards(): void
    {
        $owner = $this->makeUser();
        $server = $this->makeServer($owner);
        $this->addServerMember($server, $owner);
        $channel = $this->makeServerChannel($server, ['kind' => ServerChannelKind::Text]);
        Sanctum::actingAs($owner, ['*']);

        $first = $this->postJson('/api/server-channel-messages', ['server_channel_id' => $channel->id, 'message' => 'a'])->json('message');
        $second = $this->postJson('/api/server-channel-messages', ['server_channel_id' => $channel->id, 'message' => 'b'])->json('message');

        $this->postJson("/api/server-channel-messages/{$channel->id}/read")->assertJsonPath('last_read_message_id', $second);
        $this->postJson("/api/server-channel-messages/{$channel->id}/read", ['message_id' => $first])
            ->assertJsonPath('last_read_message_id', $second);
    }

    public function test_outsider_cannot_mark_or_mute_foreign_server(): void
    {
        $owner = $this->makeUser();
        $stranger = $this->makeUser();
        $server = $this->makeServer($owner);
        $channel = $this->makeServerChannel($server, ['kind' => ServerChannelKind::Text]);
        Sanctum::actingAs($stranger, ['*']);

        $this->postJson("/api/server-channel-messages/{$channel->id}/read")->assertForbidden();
        $this->postJson('/api/notification-mutes', ['target_type' => 'server', 'target_id' => $server->id])->assertForbidden();
    }

    public function test_muted_direct_chat_gets_no_push_until_unmuted(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser();
        $channel = $this->makeChannel(['status' => ChannelType::Private]);
        $this->addMember($channel, $alice);
        $this->addMember($channel, $bob);

        Sanctum::actingAs($bob, ['*']);
        $this->postJson('/api/notification-mutes', ['target_type' => 'channel', 'target_id' => $channel->id, 'minutes' => 60])
            ->assertOk()
            ->assertJsonPath('mutes.0.target_id', $channel->id);

        Sanctum::actingAs($alice, ['*']);
        $this->postJson('/api/messages', ['channels_id' => $channel->id, 'message' => 'тихо'])->assertCreated();
        $this->assertSame([], collect($this->sent)->firstWhere('payload.kind', 'message')['users'] ?? []);

        Sanctum::actingAs($bob, ['*']);
        $this->deleteJson('/api/notification-mutes', ['target_type' => 'channel', 'target_id' => $channel->id])
            ->assertOk()->assertJsonPath('mutes', []);

        $this->sent = [];
        Sanctum::actingAs($alice, ['*']);
        $this->postJson('/api/messages', ['channels_id' => $channel->id, 'message' => 'громко'])->assertCreated();
        $this->assertSame([$bob->id], collect($this->sent)->firstWhere('payload.kind', 'message')['users']);
    }

    public function test_expired_mute_is_ignored(): void
    {
        $bob = $this->makeUser();
        $channel = $this->makeChannel(['status' => ChannelType::Private]);
        $this->addMember($channel, $bob);
        Sanctum::actingAs($bob, ['*']);

        $this->postJson('/api/notification-mutes', ['target_type' => 'channel', 'target_id' => $channel->id, 'minutes' => 15])->assertOk();
        $this->travel(16)->minutes();

        $this->getJson('/api/notification-mutes')->assertOk()->assertJsonPath('mutes', []);
    }

    public function test_categories_group_channels_and_deleting_one_keeps_its_channels(): void
    {
        $owner = $this->makeUser();
        $server = $this->makeServer($owner);
        $this->addServerMember($server, $owner);
        Sanctum::actingAs($owner, ['*']);

        $category = $this->postJson("/api/servers/{$server->id}/channels", ['name' => 'Игры', 'kind' => 4, 'category_id' => 999])
            ->assertCreated()->assertJsonPath('channel.category_id', null)->json('channel');
        $channel = $this->postJson("/api/servers/{$server->id}/channels", ['name' => 'кс', 'kind' => 1, 'category_id' => $category['id']])
            ->assertCreated()->assertJsonPath('channel.category_id', $category['id'])->json('channel');

        $this->postJson("/api/servers/{$server->id}/channels/{$channel['id']}", ['category_id' => null])
            ->assertOk()->assertJsonPath('channel.category_id', null);
        $this->postJson("/api/servers/{$server->id}/channels/{$channel['id']}", ['category_id' => $category['id']])->assertOk();

        $this->deleteJson("/api/servers/{$server->id}/channels/{$category['id']}")->assertOk();
        $this->assertNull(ServerChannel::query()->find($channel['id'])->category_id);
    }

    public function test_muted_server_skips_everyone_push_but_keeps_direct_mention(): void
    {
        $owner = $this->makeUser();
        $bob = $this->makeUser();
        $server = $this->makeServer($owner);
        $this->addServerMember($server, $owner);
        $this->addServerMember($server, $bob);
        $channel = $this->makeServerChannel($server, ['kind' => ServerChannelKind::Text]);

        Sanctum::actingAs($bob, ['*']);
        $this->postJson('/api/notification-mutes', ['target_type' => 'server', 'target_id' => $server->id])->assertOk();

        Sanctum::actingAs($owner, ['*']);
        $this->postJson('/api/server-channel-messages', ['server_channel_id' => $channel->id, 'message' => '@everyone сбор'])->assertCreated();
        $this->assertSame([], collect($this->sent)->firstWhere('payload.kind', 'mention')['users']);

        $this->sent = [];
        $this->postJson('/api/server-channel-messages', ['server_channel_id' => $channel->id, 'message' => "<@{$bob->id}> ты где"])->assertCreated();
        $this->assertSame([$bob->id], collect($this->sent)->firstWhere('payload.kind', 'mention')['users']);
    }
}
