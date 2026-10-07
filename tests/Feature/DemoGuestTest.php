<?php

namespace Tests\Feature;

use App\Models\Conversations\Channel;
use App\Models\Conversations\Message;
use App\Models\Servers\Server;
use App\Models\Servers\ServerChannel;
use App\Models\Social\Friend;
use App\Models\User;
use App\Services\Account\DemoGuestService;
use App\Services\Admin\AdminStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemoGuestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_demo_start_returns_working_token_and_ready_world(): void
    {
        $response = $this->postJson('/api/demo')
            ->assertCreated()
            ->assertJsonPath('user.is_demo', true)
            ->assertJsonPath('user.email_verified', true);

        $token = $response->json('token');
        $serverId = $response->json('server_id');
        $channelId = $response->json('channel_id');
        $this->assertNotEmpty($response->json('user.demo_expires_at'));

        $this->withToken($token)->getJson('/api/servers')
            ->assertOk()
            ->assertJsonPath('servers.0.id', $serverId)
            ->assertJsonPath('servers.0.name', 'DayZ')
            ->assertJsonPath('servers.0.icon', Storage::disk('public')->url('server-icons/demo_dayz_v1.jpg'));

        // Переписка зашифрована как обычная и читается через обычный API.
        $this->withToken($token)->getJson("/api/server-channel-messages/{$channelId}")
            ->assertOk()
            ->assertJsonFragment(['message' => 'возьмите в пати, у меня топор и две банки фасоли 😅'])
            ->assertJsonFragment(['emoji' => '😂']);

        $this->withToken($token)->getJson('/api/friends')->assertOk();
        $this->assertSame(4, Friend::query()->where('friend_id', $response->json('user.id'))->count());
        $this->assertSame(2, Channel::query()->whereHas('members', fn ($q) => $q->where('users_id', $response->json('user.id')))->count());
        $this->assertSame(6, ServerChannel::query()->where('server_id', $serverId)->count());

        Storage::disk('public')->assertExists('avatars/demo_lunitunz_v3.jpg');

        // Демо-друг стримит DayZ в голосовом канале: он в участниках с демонстрацией, превью отдаётся.
        $stream = ServerChannel::query()->where('server_id', $serverId)->where('name', 'DayZ')->firstOrFail();
        $streamer = User::query()->where('login', 'demo_lunitunz')->firstOrFail();
        $this->withToken($token)->getJson("/api/server-channels/{$stream->id}/calls/participants")
            ->assertOk()
            ->assertJsonFragment(['user_id' => $streamer->id, 'is_screen_sharing' => true]);
        $this->withToken($token)->get("/api/server-channels/{$stream->id}/calls/screen-preview/{$streamer->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_personas_are_shared_between_guests(): void
    {
        $this->postJson('/api/demo')->assertCreated();
        $this->postJson('/api/demo')->assertCreated();

        $this->assertSame(4, User::query()->where('demo_kind', User::DEMO_PERSONA)->count());
        $this->assertSame(2, User::query()->where('demo_kind', User::DEMO_GUEST)->count());
    }

    public function test_guest_cannot_reach_real_people(): void
    {
        $real = User::factory()->create();
        $guest = $this->startDemo();

        Sanctum::actingAs($guest);

        $this->postJson('/api/friends/requests', ['friend_login' => $real->login])
            ->assertForbidden()
            ->assertJsonPath('code', 'DEMO_RESTRICTED');
        $this->postJson('/api/invites/abc123/join')->assertForbidden();
        $this->postJson('/api/support/messages', ['body' => 'привет'])->assertForbidden();
        // Чужую почту демо-аккаунтом не занять.
        $this->postJson('/api/auth/profile', ['email' => 'victim@example.com'])->assertForbidden();

        // Остальное работает как у всех.
        $this->getJson('/api/servers')->assertOk();
        $this->postJson('/api/auth/profile', ['name' => 'Женя'])->assertOk();
        $this->assertSame('Женя', $guest->fresh()->name);
    }

    public function test_prune_removes_expired_guest_with_everything_but_keeps_personas(): void
    {
        $guest = $this->startDemo();
        $serverId = Server::query()->where('owner_id', $guest->id)->value('id');
        $channelIds = Channel::query()->whereHas('members', fn ($q) => $q->where('users_id', $guest->id))->pluck('id');

        $this->artisan('demo:prune')->assertSuccessful();
        $this->assertModelExists($guest);

        $this->travel(DemoGuestService::TTL_HOURS + 1)->hours();
        $this->artisan('demo:prune')->assertSuccessful();

        $this->assertModelMissing($guest);
        $this->assertNull(Server::query()->find($serverId));
        $this->assertSame(0, ServerChannel::query()->where('server_id', $serverId)->count());
        $this->assertSame(0, Channel::query()->whereKey($channelIds)->count());
        $this->assertSame(0, Message::query()->whereIn('channels_id', $channelIds)->count());
        $this->assertSame(0, Friend::query()->count());
        $this->assertSame(4, User::query()->where('demo_kind', User::DEMO_PERSONA)->count());
    }

    public function test_demo_accounts_do_not_count_in_admin_stats(): void
    {
        User::factory()->create(['last_online' => now()]);
        $this->startDemo()->touchLastOnline();

        $stats = app(AdminStatsService::class)->snapshot();

        $this->assertSame(1, $stats['users_total']);
        $this->assertSame(1, $stats['signups_today']);
        $this->assertSame(1, $stats['users_online']);
    }

    public function test_guest_online_ping_keeps_demo_friends_online(): void
    {
        $guest = $this->startDemo();
        $this->travel(5)->minutes();

        Sanctum::actingAs($guest);
        $this->postJson('/api/auth/online')->assertOk();

        $this->assertTrue(User::query()->where('login', 'demo_lunitunz')->firstOrFail()->isOnline());
    }

    public function test_demo_start_is_rate_limited_per_ip(): void
    {
        $this->postJson('/api/demo')->assertCreated();
        $this->postJson('/api/demo')->assertCreated();
        $this->postJson('/api/demo')->assertCreated();
        $this->postJson('/api/demo')->assertTooManyRequests();
    }

    private function startDemo(): User
    {
        return User::query()->findOrFail($this->postJson('/api/demo')->assertCreated()->json('user.id'));
    }
}
