<?php

namespace Tests\Feature;

use App\Enums\ServerChannelKind;
use App\Enums\ServerPermission;
use App\Events\CallScreenShareStarted;
use App\Events\CallScreenShareStopped;
use App\Events\WebRTCSignal;
use App\Models\Conversations\Call;
use App\Models\Servers\ServerChannelRoleOverwrite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithCalls;
use Tests\Concerns\InteractsWithServers;
use Tests\TestCase;

class ServerChannelCallControllerTest extends TestCase
{
    use InteractsWithCalls, InteractsWithServers, RefreshDatabase;

    public function test_member_can_join_and_leave_voice_channel(): void
    {
        $owner = $this->makeUser();
        $server = $this->makeServer($owner);
        $this->addServerMember($server, $owner);
        $voice = $this->makeServerChannel($server, ['kind' => ServerChannelKind::Voice]);

        $member = $this->makeUser();
        $this->addServerMember($server, $member);

        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/server-channels/{$voice->id}/calls", ['session_id' => 'owner-tab-1'])
            ->assertOk()->assertJsonPath('call.server_channel_id', $voice->id);

        Sanctum::actingAs($member, ['*']);
        $this->postJson("/api/server-channels/{$voice->id}/calls", ['session_id' => 'member-tab-1'])
            ->assertOk();

        $this->getJson("/api/server-channels/{$voice->id}/calls/participants")
            ->assertOk()->assertJsonCount(2, 'participants');

        $this->postJson("/api/server-channels/{$voice->id}/calls/leave", ['session_id' => 'member-tab-1'])
            ->assertOk();

        $this->getJson("/api/server-channels/{$voice->id}/calls/participants")
            ->assertOk()->assertJsonCount(1, 'participants');
    }

    public function test_cannot_join_text_channel_as_voice(): void
    {
        $owner = $this->makeUser();
        $server = $this->makeServer($owner);
        $this->addServerMember($server, $owner);
        $text = $this->makeServerChannel($server, ['kind' => ServerChannelKind::Text]);

        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/server-channels/{$text->id}/calls", ['session_id' => 'tab-1'])
            ->assertStatus(422);
    }

    public function test_non_member_cannot_join_voice_channel(): void
    {
        $owner = $this->makeUser();
        $server = $this->makeServer($owner);
        $this->addServerMember($server, $owner);
        $voice = $this->makeServerChannel($server, ['kind' => ServerChannelKind::Voice]);

        $stranger = $this->makeUser();
        Sanctum::actingAs($stranger, ['*']);

        $this->postJson("/api/server-channels/{$voice->id}/calls", ['session_id' => 'tab-1'])
            ->assertStatus(403);
    }

    public function test_leaving_last_participant_ends_call(): void
    {
        $owner = $this->makeUser();
        $server = $this->makeServer($owner);
        $this->addServerMember($server, $owner);
        $voice = $this->makeServerChannel($server, ['kind' => ServerChannelKind::Voice]);

        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/server-channels/{$voice->id}/calls", ['session_id' => 'tab-1'])->assertOk();
        $this->postJson("/api/server-channels/{$voice->id}/calls/leave", ['session_id' => 'tab-1'])
            ->assertOk()->assertJsonPath('message', 'Вы вышли, звонок завершён');
    }

    public function test_channel_list_shows_active_voice_participants(): void
    {
        $owner = $this->makeUser();
        $server = $this->makeServer($owner);
        $this->addServerMember($server, $owner);
        $voice = $this->makeServerChannel($server, ['kind' => ServerChannelKind::Voice]);

        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/server-channels/{$voice->id}/calls", ['session_id' => 'tab-1'])->assertOk();

        $this->getJson("/api/servers/{$server->id}/channels")
            ->assertOk()
            ->assertJsonPath('channels.0.active_count', 1);
    }

    public function test_webrtc_signal_relays_only_between_active_voice_participants(): void
    {
        Event::fake([WebRTCSignal::class]);

        $owner = $this->makeUser();
        $server = $this->makeServer($owner);
        $this->addServerMember($server, $owner);
        $voice = $this->makeServerChannel($server, ['kind' => ServerChannelKind::Voice]);

        $member = $this->makeUser();
        $this->addServerMember($server, $member);

        // Состоит на сервере, но не зашёл в голосовой — сигналы ему идти не должны.
        $bystander = $this->makeUser();
        $this->addServerMember($server, $bystander);

        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/server-channels/{$voice->id}/calls", ['session_id' => 'owner-1'])->assertOk();

        Sanctum::actingAs($member, ['*']);
        $this->postJson("/api/server-channels/{$voice->id}/calls", ['session_id' => 'member-1'])->assertOk();

        $call = Call::query()->where('server_channel_id', $voice->id)->firstOrFail();

        $this->postJson("/api/webrtc/{$call->call_id}/signal", [
            'type' => 'candidate',
            'candidate' => ['candidate' => 'abc'],
        ])->assertOk();

        Event::assertDispatched(WebRTCSignal::class, fn (WebRTCSignal $e) => $e->toUserId === $owner->id);
        Event::assertNotDispatched(WebRTCSignal::class, fn (WebRTCSignal $e) => $e->toUserId === $bystander->id);
        Event::assertNotDispatched(WebRTCSignal::class, fn (WebRTCSignal $e) => $e->toUserId === $member->id);
    }

    public function test_webrtc_signal_rejects_target_not_in_voice_channel(): void
    {
        $owner = $this->makeUser();
        $server = $this->makeServer($owner);
        $this->addServerMember($server, $owner);
        $voice = $this->makeServerChannel($server, ['kind' => ServerChannelKind::Voice]);

        $bystander = $this->makeUser();
        $this->addServerMember($server, $bystander);

        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/server-channels/{$voice->id}/calls", ['session_id' => 'owner-1'])->assertOk();

        $call = Call::query()->where('server_channel_id', $voice->id)->firstOrFail();

        $this->postJson("/api/webrtc/{$call->call_id}/signal", [
            'type' => 'offer',
            'sdp' => 'v=0...',
            'to_user_id' => $bystander->id,
        ])->assertStatus(403)->assertJsonPath('message', 'Получатель не участник этого канала');
    }

    /**
     * Регрессия: CallScreenShareStarted/Stopped раньше вещали жёстко на "channel.{channel_id}" —
     * для голосового канала сервера channel_id всегда null, событие уходило в никуда и
     * демонстрация экрана не синхронизировалась между участниками.
     */
    public function test_screen_share_heartbeat_broadcasts_on_server_voice_channel(): void
    {
        Event::fake([CallScreenShareStarted::class, CallScreenShareStopped::class]);

        $owner = $this->makeUser();
        $server = $this->makeServer($owner);
        $this->addServerMember($server, $owner);
        $voice = $this->makeServerChannel($server, ['kind' => ServerChannelKind::Voice]);

        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/server-channels/{$voice->id}/calls", ['session_id' => 'owner-1'])->assertOk();

        $this->postJson("/api/server-channels/{$voice->id}/calls/heartbeat", [
            'session_id' => 'owner-1',
            'screen_sharing' => true,
        ])->assertOk();

        Event::assertDispatched(CallScreenShareStarted::class, function (CallScreenShareStarted $e) use ($voice) {
            $this->assertNull($e->call->channel_id);
            $this->assertSame($voice->id, $e->call->server_channel_id);
            $this->assertSame('private-server-voice.'.$voice->id, $e->broadcastOn()[0]->name);

            return true;
        });

        $this->postJson("/api/server-channels/{$voice->id}/calls/heartbeat", [
            'session_id' => 'owner-1',
            'screen_sharing' => false,
        ])->assertOk();

        Event::assertDispatched(CallScreenShareStopped::class, function (CallScreenShareStopped $e) use ($voice) {
            $this->assertSame('private-server-voice.'.$voice->id, $e->broadcastOn()[0]->name);

            return true;
        });
    }

    /** Heartbeat проверяет права тем же правилом, что ServerChannelPolicy::call, и отдаёт ограничения голоса. */
    public function test_voice_heartbeat_checks_access_like_policy(): void
    {
        $owner = $this->makeUser();
        $server = $this->makeServer($owner);
        $this->addServerMember($server, $owner);
        $voice = $this->makeServerChannel($server, ['kind' => ServerChannelKind::Voice]);

        $member = $this->makeUser();
        $membership = $this->addServerMember($server, $member);
        $stranger = $this->makeUser();

        Sanctum::actingAs($stranger, ['*']);
        $this->postJson("/api/server-channels/{$voice->id}/calls/heartbeat", ['session_id' => 's-1'])
            ->assertForbidden()->assertJsonPath('message', 'Нет доступа');

        // Участнику запрещено подключаться к каналу через @everyone.
        ServerChannelRoleOverwrite::create([
            'server_channel_id' => $voice->id,
            'server_role_id' => $membership->fresh()->roles()->first()->id,
            'allow' => 0,
            'deny' => ServerPermission::CONNECT_VOICE,
        ]);
        Sanctum::actingAs($member, ['*']);
        $this->postJson("/api/server-channels/{$voice->id}/calls/heartbeat", ['session_id' => 's-2'])
            ->assertForbidden()->assertJsonPath('message', 'Нет прав');

        // Владелец проходит всегда и получает ограничения голоса в ответе.
        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/server-channels/{$voice->id}/calls", ['session_id' => 'owner-1'])->assertOk();
        $this->postJson("/api/server-channels/{$voice->id}/calls/heartbeat", ['session_id' => 'owner-1'])
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('can_speak', true)
            ->assertJsonPath('voice_muted', false);
    }

    /** Вкладка браузера в фоне не шлёт сигнал онлайна, но человек в голосе — он в сети. */
    public function test_voice_heartbeat_keeps_user_online_without_touching_updated_at(): void
    {
        $owner = $this->makeUser();
        $server = $this->makeServer($owner);
        $this->addServerMember($server, $owner);
        $voice = $this->makeServerChannel($server, ['kind' => ServerChannelKind::Voice]);

        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/server-channels/{$voice->id}/calls", ['session_id' => 'owner-1'])->assertOk();

        $owner->forceFill(['last_online' => now()->subMinutes(10)])->saveQuietly();
        $updatedAt = $owner->fresh()->updated_at;

        $this->travel(3)->seconds();
        $this->postJson("/api/server-channels/{$voice->id}/calls/heartbeat", ['session_id' => 'owner-1'])->assertOk();

        $owner->refresh();
        $this->assertTrue($owner->isOnline());
        $this->assertEquals($updatedAt, $owner->updated_at);
    }
}
