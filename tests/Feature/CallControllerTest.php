<?php

namespace Tests\Feature;

use App\Enums\MemberCallStatus;
use App\Enums\MembershipStatus;
use App\Models\Conversations\Call;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithCalls;
use Tests\TestCase;

class CallControllerTest extends TestCase
{
    use InteractsWithCalls;
    use RefreshDatabase;

    private const CLIENT_BUILD = '2026-10-04T18:00:00.000Z';

    public function test_non_member_cannot_use_calls_in_channel(): void
    {
        $channel = $this->makeChannel();
        $member = $this->makeUser();
        $this->addMember($channel, $member, MembershipStatus::Member, MemberCallStatus::InCall);
        $this->makeActiveCall($channel, $member);

        Sanctum::actingAs($this->makeUser());

        $this->postJson("/api/calls/{$channel->id}")->assertForbidden();
        $this->postJson("/api/calls/{$channel->id}/accept", ['session_id' => 'stranger'])->assertForbidden();
        $this->postJson("/api/calls/{$channel->id}/decline")->assertForbidden();
        $this->postJson("/api/calls/{$channel->id}/leave", ['session_id' => 'stranger'])->assertForbidden();
        $this->postJson("/api/calls/{$channel->id}/heartbeat", ['session_id' => 'stranger'])->assertForbidden();
        $this->getJson("/api/calls/{$channel->id}/screen-preview/{$member->id}")->assertForbidden();

        $this->assertSame(1, Call::query()->count());
        $this->assertDatabaseCount('call_sessions', 0);
    }

    public function test_member_starts_call_and_last_participant_leaving_ends_it(): void
    {
        $channel = $this->makeChannel();
        $user = $this->makeUser();
        $this->addMember($channel, $user);

        Sanctum::actingAs($user);

        $this->postJson("/api/calls/{$channel->id}", ['client_build' => self::CLIENT_BUILD])
            ->assertCreated()
            ->assertJsonPath('call.status', 'active');

        $this->postJson("/api/calls/{$channel->id}/leave")
            ->assertOk()
            ->assertJsonPath('message', 'Вы вышли, звонок завершён');
    }

    // Вкладка от сборки до 2026-10-04 не присылает client_build: она могла звонить мимо LiveKit,
    // и её никто бы не услышал. Звонить и принимать звонок ей не даём — пусть обновится.
    public function test_outdated_client_cannot_start_or_accept_call(): void
    {
        $channel = $this->makeChannel();
        $initiator = $this->makeUser();
        $user = $this->makeUser();
        $this->addMember($channel, $initiator, MembershipStatus::Member, MemberCallStatus::InCall);
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        $this->postJson("/api/calls/{$channel->id}")
            ->assertStatus(426)
            ->assertJsonPath('code', 'CLIENT_OUTDATED');
        $this->assertSame(0, Call::query()->count());

        $call = $this->makeActiveCall($channel, $initiator);
        $this->postJson("/api/calls/{$channel->id}/accept", ['call_id' => $call->call_id, 'session_id' => 's1'])
            ->assertStatus(426);

        config(['services.client_build.min' => '2026-10-05T00:00:00.000Z']);
        $this->postJson("/api/calls/{$channel->id}/accept", ['call_id' => $call->call_id, 'session_id' => 's1', 'client_build' => self::CLIENT_BUILD])
            ->assertStatus(426);

        config(['services.client_build.min' => null]);
        $this->postJson("/api/calls/{$channel->id}/accept", ['call_id' => $call->call_id, 'session_id' => 's1', 'client_build' => self::CLIENT_BUILD])
            ->assertOk();
    }

    public function test_accept_of_finished_call_does_not_join_new_call_in_channel(): void
    {
        $channel = $this->makeChannel();
        $initiator = $this->makeUser();
        $user = $this->makeUser();
        $this->addMember($channel, $initiator, MembershipStatus::Member, MemberCallStatus::InCall);
        $this->addMember($channel, $user);
        $this->makeActiveCall($channel, $initiator);
        Sanctum::actingAs($user);

        $this->postJson("/api/calls/{$channel->id}/accept", ['call_id' => 'finished-call', 'session_id' => 's1', 'client_build' => self::CLIENT_BUILD])
            ->assertStatus(409);
        $this->assertDatabaseCount('call_sessions', 0);
    }
}
