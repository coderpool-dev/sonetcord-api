<?php

namespace Tests\Feature;

use App\Enums\CallLeaveOutcome;
use App\Enums\CallStatus;
use App\Enums\ChannelType;
use App\Enums\FriendStatus;
use App\Enums\MemberCallStatus;
use App\Enums\MembershipStatus;
use App\Events\CallEnded;
use App\Events\CallParticipantJoined;
use App\Events\CallParticipantLeft;
use App\Events\CallScreenShareStarted;
use App\Events\CallScreenShareStopped;
use App\Events\CallSessionSuperseded;
use App\Exceptions\ApiException;
use App\Models\Conversations\Call;
use App\Models\Conversations\CallSession;
use App\Models\Conversations\Channel;
use App\Models\Conversations\ChannelMember;
use App\Models\Conversations\Message;
use App\Models\Social\Friend;
use App\Models\User;
use App\Services\Conversations\CallPresenceService;
use App\Services\Conversations\CallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithCalls;
use Tests\TestCase;

class CallServiceTest extends TestCase
{
    use InteractsWithCalls;
    use RefreshDatabase;

    private CallService $service;

    private CallPresenceService $presence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CallService::class);
        $this->presence = app(CallPresenceService::class);
    }

    public function test_create_starts_active_call_for_member(): void
    {
        $channel = $this->makeChannel();
        $user = $this->makeUser();
        $this->addMember($channel, $user);

        $start = $this->service->create($user, $channel->id);

        $this->assertTrue($start->created);
        $this->assertDatabaseHas('calls', [
            'channel_id' => $channel->id,
            'initiator_id' => $user->id,
            'status' => 'active',
        ]);
        $this->assertSame(MemberCallStatus::InCall, $this->callStatusOf($channel, $user->id));
    }

    public function test_create_rejects_call_to_blocked_peer_in_private_channel(): void
    {
        [$channel, $caller, $peer] = $this->privateChannel();
        Friend::create(['users_id' => $caller->id, 'friend_id' => $peer->id, 'status' => FriendStatus::Blocked]);

        $this->assertRejectedWith(403, fn () => $this->service->create($caller, $channel->id));
        $this->assertDatabaseMissing('calls', ['channel_id' => $channel->id]);
    }

    public function test_create_rejects_call_when_peer_blocked_in_reverse_direction(): void
    {
        [$channel, $caller, $peer] = $this->privateChannel();
        // Блокировка записана в обратную сторону — должна считаться взаимной.
        Friend::create(['users_id' => $peer->id, 'friend_id' => $caller->id, 'status' => FriendStatus::Blocked]);

        $this->assertRejectedWith(403, fn () => $this->service->create($caller, $channel->id));
    }

    public function test_create_allows_group_call_despite_block(): void
    {
        $channel = $this->makeChannel();
        $caller = $this->makeUser();
        $peer = $this->makeUser();
        $this->addMember($channel, $caller, MembershipStatus::Admin);
        $this->addMember($channel, $peer);
        Friend::create(['users_id' => $caller->id, 'friend_id' => $peer->id, 'status' => FriendStatus::Blocked]);

        $this->assertTrue($this->service->create($caller, $channel->id)->created);
        $this->assertDatabaseHas('calls', ['channel_id' => $channel->id, 'status' => 'active']);
    }

    public function test_create_rejects_private_call_between_non_friends(): void
    {
        // Личный чат между ними есть (например, остался после удаления из друзей), но дружбы нет.
        [$channel, $caller] = $this->privateChannel();

        $this->assertRejectedWith(403, fn () => $this->service->create($caller, $channel->id));
        $this->assertDatabaseMissing('calls', ['channel_id' => $channel->id]);
    }

    public function test_create_allows_private_call_between_friends(): void
    {
        [$channel, $caller, $peer] = $this->privateChannel();
        Friend::create(['users_id' => $caller->id, 'friend_id' => $peer->id, 'status' => FriendStatus::Accepted]);

        $this->assertTrue($this->service->create($caller, $channel->id)->created);
        $this->assertDatabaseHas('calls', ['channel_id' => $channel->id, 'status' => 'active']);
    }

    public function test_create_returns_existing_active_call_without_duplicating(): void
    {
        $channel = $this->makeChannel();
        $user = $this->makeUser();
        $this->addMember($channel, $user);
        $existing = $this->makeActiveCall($channel, $user);

        $start = $this->service->create($user, $channel->id);

        $this->assertFalse($start->created);
        $this->assertSame($existing->call_id, $start->call['call_id']);
        $this->assertSame(1, Call::where('channel_id', $channel->id)->count());
    }

    public function test_accept_fails_when_no_active_call(): void
    {
        $channel = $this->makeChannel();
        $user = $this->makeUser();
        $this->addMember($channel, $user);

        $this->assertRejectedWith(404, fn () => $this->service->accept($user, $channel->id));

        // Статус участника не меняется, раз звонка нет.
        $this->assertSame(MemberCallStatus::Idle, $this->callStatusOf($channel, $user->id));
    }

    public function test_accept_marks_member_active_when_call_exists(): void
    {
        $channel = $this->makeChannel();
        $initiator = $this->makeUser();
        $joiner = $this->makeUser();
        $this->addMember($channel, $initiator, MembershipStatus::Admin, MemberCallStatus::InCall);
        $this->addMember($channel, $joiner);
        $this->makeActiveCall($channel, $initiator);

        $this->service->accept($joiner, $channel->id);

        $this->assertSame(MemberCallStatus::InCall, $this->callStatusOf($channel, $joiner->id));
    }

    // 2026-10-04: клиент Viper восстанавливал старый звонок, а accept по каналу пустил его в новый
    // звонок yashik — в комнату которого клиент так и не вошёл. Чужой звонок не принимаем.
    public function test_accept_rejects_call_that_was_replaced_by_a_new_one(): void
    {
        $channel = $this->makeChannel();
        $initiator = $this->makeUser();
        $joiner = $this->makeUser();
        $this->addMember($channel, $initiator, MembershipStatus::Admin, MemberCallStatus::InCall);
        $this->addMember($channel, $joiner);
        $call = $this->makeActiveCall($channel, $initiator);

        $this->assertRejectedWith(409, fn () => $this->service->accept($joiner, $channel->id, 'sess', 'ended-call-id'));
        $this->assertSame(MemberCallStatus::Idle, $this->callStatusOf($channel, $joiner->id));

        $this->service->accept($joiner, $channel->id, 'sess', (string) $call->call_id);
        $this->assertSame(MemberCallStatus::InCall, $this->callStatusOf($channel, $joiner->id));
    }

    public function test_leave_ends_call_when_last_participant_leaves(): void
    {
        Event::fake([CallEnded::class]);

        $channel = $this->makeChannel();
        $user = $this->makeUser();
        $this->addMember($channel, $user, MembershipStatus::Member, MemberCallStatus::InCall);
        $call = $this->makeActiveCall($channel, $user);

        $this->assertSame(CallLeaveOutcome::CallEnded, $this->service->leave($user, $channel->id));
        $this->assertSame(CallStatus::Ended, $call->fresh()->status);

        // Рассылаем всем в беседе, не только тем, кто был в звонке — иначе баннер
        // «Присоединиться» у остальных узнаёт о конце звонка только по опросу.
        Event::assertDispatched(
            CallEnded::class,
            fn (CallEnded $e) => $e->call->call_id === $call->call_id,
        );
    }

    public function test_leave_keeps_call_alive_when_others_remain(): void
    {
        $channel = $this->makeChannel();
        $leaving = $this->makeUser();
        $staying = $this->makeUser();
        $this->addMember($channel, $leaving, MembershipStatus::Member, MemberCallStatus::InCall);
        $this->addMember($channel, $staying, MembershipStatus::Member, MemberCallStatus::InCall);
        $call = $this->makeActiveCall($channel, $leaving);

        $this->assertSame(CallLeaveOutcome::Left, $this->service->leave($leaving, $channel->id));
        $this->assertSame(CallStatus::Active, $call->fresh()->status);
    }

    public function test_active_calls_cleanup_finalizes_empty_call_message(): void
    {
        $channel = $this->makeChannel();
        $user = $this->makeUser();
        $joiner = $this->makeUser();
        $this->addMember($channel, $user);
        $this->addMember($channel, $joiner);

        $this->service->create($user, $channel->id);
        $this->service->accept($joiner, $channel->id);

        $call = Call::where('channel_id', $channel->id)->active()->firstOrFail();
        Call::where('call_id', $call->call_id)->update(['created_at' => now()->subSeconds(10)]);
        ChannelMember::where('channels_id', $channel->id)->update(['call_status' => MemberCallStatus::Idle]);

        $this->service->activeCallsForUser($user);

        $this->assertSame(CallStatus::Ended, $call->fresh()->status);

        $message = Message::where('channels_id', $channel->id)
            ->where('type', 'system')
            ->where('meta->event', 'call_ended')
            ->firstOrFail();

        $this->assertSame($call->call_id, $message->meta['call_id']);
    }

    public function test_accept_with_session_registers_call_session(): void
    {
        $channel = $this->makeChannel();
        $initiator = $this->makeUser();
        $joiner = $this->makeUser();
        $this->addMember($channel, $initiator, MembershipStatus::Admin, MemberCallStatus::InCall);
        $this->addMember($channel, $joiner);
        $call = $this->makeActiveCall($channel, $initiator);

        $this->service->accept($joiner, $channel->id, 'sess-A');

        $this->assertDatabaseHas('call_sessions', [
            'call_id' => $call->call_id,
            'user_id' => $joiner->id,
            'session_id' => 'sess-A',
        ]);
    }

    public function test_create_leaves_user_from_other_active_calls(): void
    {
        $oldChannel = $this->makeChannel();
        $newChannel = $this->makeChannel();
        $user = $this->makeUser();
        $other = $this->makeUser();
        $this->addMember($oldChannel, $user, MembershipStatus::Member, MemberCallStatus::InCall);
        $this->addMember($oldChannel, $other, MembershipStatus::Member, MemberCallStatus::InCall);
        $this->addMember($newChannel, $user);
        $oldCall = $this->makeActiveCall($oldChannel, $user);
        $this->makeSession($oldCall, $user->id, 'old-session');

        $this->service->create($user, $newChannel->id);

        $this->assertSame(MemberCallStatus::Idle, $this->callStatusOf($oldChannel, $user->id));
        $this->assertSame(MemberCallStatus::InCall, $this->callStatusOf($newChannel, $user->id));
        $this->assertDatabaseMissing('call_sessions', ['session_id' => 'old-session']);
        $this->assertSame(CallStatus::Active, $oldCall->fresh()->status);
    }

    public function test_accept_leaves_other_calls_and_supersedes_old_sessions(): void
    {
        Event::fake([CallParticipantLeft::class, CallSessionSuperseded::class]);

        $oldChannel = $this->makeChannel();
        $newChannel = $this->makeChannel();
        $user = $this->makeUser();
        $newInitiator = $this->makeUser();
        $this->addMember($oldChannel, $user, MembershipStatus::Member, MemberCallStatus::InCall);
        $this->addMember($newChannel, $user);
        $this->addMember($newChannel, $newInitiator, MembershipStatus::Member, MemberCallStatus::InCall);
        $oldCall = $this->makeActiveCall($oldChannel, $user);
        $newCall = $this->makeActiveCall($newChannel, $newInitiator);
        $this->makeSession($oldCall, $user->id, 'old-session');

        $this->service->accept($user, $newChannel->id, 'new-session');

        $this->assertSame(MemberCallStatus::Idle, $this->callStatusOf($oldChannel, $user->id));
        $this->assertSame(MemberCallStatus::InCall, $this->callStatusOf($newChannel, $user->id));
        $this->assertDatabaseMissing('call_sessions', ['session_id' => 'old-session']);
        $this->assertDatabaseHas('call_sessions', [
            'call_id' => $newCall->call_id,
            'user_id' => $user->id,
            'session_id' => 'new-session',
        ]);
        $this->assertSame(CallStatus::Ended, $oldCall->fresh()->status);

        Event::assertDispatched(
            CallSessionSuperseded::class,
            fn (CallSessionSuperseded $e) => $e->userId === (int) $user->id
                && $e->callId === $oldCall->call_id
                && $e->winningSessionId === 'new-session',
        );
        Event::assertDispatched(CallParticipantLeft::class);
    }

    public function test_accept_from_new_device_supersedes_old_session(): void
    {
        Event::fake([CallSessionSuperseded::class]);

        $channel = $this->makeChannel();
        $user = $this->makeUser();
        $this->addMember($channel, $user, MembershipStatus::Member, MemberCallStatus::InCall);
        $call = $this->makeActiveCall($channel, $user);

        $this->service->accept($user, $channel->id, 'pc-session');
        $this->service->accept($user, $channel->id, 'laptop-session');

        $this->assertDatabaseMissing('call_sessions', ['call_id' => $call->call_id, 'session_id' => 'pc-session']);
        $this->assertDatabaseHas('call_sessions', ['call_id' => $call->call_id, 'session_id' => 'laptop-session']);
        $this->assertSame(1, CallSession::where('call_id', $call->call_id)->where('user_id', $user->id)->count());

        Event::assertDispatched(
            CallSessionSuperseded::class,
            fn (CallSessionSuperseded $e) => $e->userId === (int) $user->id && $e->winningSessionId === 'laptop-session',
        );
    }

    /**
     * Второй accept для того же участника, кто уже отмечен «в звонке» — типично для
     * молчаливой перезагрузки клиента (например, после деплоя фронта): страница
     * восстанавливает звонок и зовёт accept заново, не послав leave. Остальные
     * участники не знают, что их P2P-соединение с этим человеком уже мертво, поэтому
     * событие должно прийти с resumed: true — фронт по нему форсирует пересоздание.
     */
    public function test_repeat_accept_while_already_in_call_marks_join_as_resumed(): void
    {
        Event::fake([CallParticipantJoined::class]);

        $channel = $this->makeChannel();
        $initiator = $this->makeUser();
        $joiner = $this->makeUser();
        $this->addMember($channel, $initiator, MembershipStatus::Admin, MemberCallStatus::InCall);
        $this->addMember($channel, $joiner, MembershipStatus::Member);
        $this->makeActiveCall($channel, $initiator);

        $this->service->accept($joiner, $channel->id, 'first-session');

        Event::assertDispatched(
            CallParticipantJoined::class,
            fn (CallParticipantJoined $e) => (int) $e->user->id === (int) $joiner->id && $e->resumed === false,
        );

        // Клиент молча перезагрузился и восстанавливает звонок — участник уже InCall.
        $this->service->accept($joiner, $channel->id, 'second-session');

        Event::assertDispatched(
            CallParticipantJoined::class,
            fn (CallParticipantJoined $e) => (int) $e->user->id === (int) $joiner->id && $e->resumed === true,
        );
    }

    public function test_heartbeat_keeps_session_alive(): void
    {
        $channel = $this->makeChannel();
        $user = $this->makeUser();
        $this->addMember($channel, $user, MembershipStatus::Member, MemberCallStatus::InCall);
        $this->makeActiveCall($channel, $user);
        $this->service->accept($user, $channel->id, 'sess-1');

        $heartbeat = $this->presence->heartbeat($user, $channel->id, 'sess-1');

        $this->assertTrue($heartbeat['active']);
        $this->assertFalse($heartbeat['superseded']);
    }

    public function test_heartbeat_restores_session_reaped_on_same_device(): void
    {
        $channel = $this->makeChannel();
        $user = $this->makeUser();
        $this->addMember($channel, $user, MembershipStatus::Member, MemberCallStatus::InCall);
        $call = $this->makeActiveCall($channel, $user);

        // Сессию убрала уборка из-за пропущенных пингов, других устройств у пользователя нет:
        // heartbeat тихо восстанавливает сессию, а не выкидывает из звонка.
        $heartbeat = $this->presence->heartbeat($user, $channel->id, 'reaped-session');

        $this->assertTrue($heartbeat['active']);
        $this->assertFalse($heartbeat['superseded']);
        $this->assertDatabaseHas('call_sessions', [
            'call_id' => $call->call_id,
            'user_id' => $user->id,
            'session_id' => 'reaped-session',
        ]);
    }

    public function test_heartbeat_restores_recently_ended_call_with_same_live_session(): void
    {
        $channel = $this->makeChannel();
        $user = $this->makeUser(['name' => 'EgoOne']);
        $this->addMember($channel, $user, MembershipStatus::Member, MemberCallStatus::InCall);
        $call = $this->makeActiveCall($channel, $user);
        $this->makeSession($call, $user->id, 'sess-1', now()->subSeconds(15));

        Message::createSystem(
            $channel->id,
            $user->id,
            'call_ended',
            ['event' => 'call_ended', 'call_id' => $call->call_id],
            'EgoOne '."\u{043d}\u{0430}\u{0447}\u{0430}\u{043b}(\u{0430}) \u{0437}\u{0432}\u{043e}\u{043d}\u{043e}\u{043a}, \u{043a}\u{043e}\u{0442}\u{043e}\u{0440}\u{044b}\u{0439} \u{043f}\u{0440}\u{043e}\u{0434}\u{043b}\u{0438}\u{043b}\u{0441}\u{044f} 207 \u{043c}\u{0438}\u{043d} 29 \u{0441}\u{0435}\u{043a}",
        );
        $call->forceFill([
            'status' => CallStatus::Ended,
            'understaffed_at' => now()->subMinutes(10),
        ])->save();

        $heartbeat = $this->presence->heartbeat($user, $channel->id, 'sess-1', false, $call->call_id);

        $this->assertTrue($heartbeat['active']);
        $this->assertFalse($heartbeat['superseded']);
        $this->assertDatabaseHas('calls', [
            'call_id' => $call->call_id,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('messages', [
            'message' => 'EgoOne '."\u{043d}\u{0430}\u{0447}\u{0430}\u{043b}(\u{0430}) \u{0437}\u{0432}\u{043e}\u{043d}\u{043e}\u{043a}, \u{043a}\u{043e}\u{0442}\u{043e}\u{0440}\u{044b}\u{0439} \u{043f}\u{0440}\u{043e}\u{0434}\u{043b}\u{0438}\u{043b}\u{0441}\u{044f} 207 \u{043c}\u{0438}\u{043d} 29 \u{0441}\u{0435}\u{043a}",
        ]);
        $this->assertDatabaseHas('messages', [
            'channels_id' => $channel->id,
            'type' => 'system',
            'message' => 'EgoOne '."\u{043f}\u{0440}\u{043e}\u{0434}\u{043e}\u{043b}\u{0436}\u{0430}\u{0435}\u{0442} \u{0437}\u{0432}\u{043e}\u{043d}\u{043e}\u{043a}",
        ]);
    }

    public function test_heartbeat_reports_superseded_when_another_device_active(): void
    {
        $channel = $this->makeChannel();
        $user = $this->makeUser();
        $this->addMember($channel, $user, MembershipStatus::Member, MemberCallStatus::InCall);
        $call = $this->makeActiveCall($channel, $user);
        $this->makeSession($call, $user->id, 'other-device');

        $heartbeat = $this->presence->heartbeat($user, $channel->id, 'my-old-session');

        $this->assertFalse($heartbeat['active']);
        $this->assertTrue($heartbeat['superseded']);
    }

    public function test_stale_session_is_reaped_and_marks_user_left(): void
    {
        $channel = $this->makeChannel();
        $ghost = $this->makeUser();
        $present = $this->makeUser();
        $this->addMember($channel, $ghost, MembershipStatus::Member, MemberCallStatus::InCall);
        $this->addMember($channel, $present, MembershipStatus::Member, MemberCallStatus::InCall);
        $call = $this->makeActiveCall($channel, $ghost);
        $this->makeSession($call, $ghost->id, 'stale', now()->subSeconds(CallSession::STALE_SECONDS));
        $this->makeSession($call, $present->id, 'fresh');

        // Любой опрос активных звонков выметает протухшие сессии.
        $this->service->activeCallsForUser($present);

        $this->assertDatabaseMissing('call_sessions', ['session_id' => 'stale']);
        $this->assertDatabaseHas('call_sessions', ['session_id' => 'fresh']);
        $this->assertSame(MemberCallStatus::Idle, $this->callStatusOf($channel, $ghost->id));
        $this->assertSame(MemberCallStatus::InCall, $this->callStatusOf($channel, $present->id));
        $this->assertSame(CallStatus::Active, $call->fresh()->status);
    }

    public function test_leave_keeps_user_present_when_other_device_remains(): void
    {
        $channel = $this->makeChannel();
        $user = $this->makeUser();
        $other = $this->makeUser();
        $this->addMember($channel, $user, MembershipStatus::Member, MemberCallStatus::InCall);
        $this->addMember($channel, $other, MembershipStatus::Member, MemberCallStatus::InCall);
        $call = $this->makeActiveCall($channel, $user);

        $this->service->accept($user, $channel->id, 'dev-1');
        $this->makeSession($call, $user->id, 'dev-2');

        $outcome = $this->service->leave($user, $channel->id, 'dev-1');

        $this->assertSame(CallLeaveOutcome::StillConnectedElsewhere, $outcome);
        $this->assertDatabaseMissing('call_sessions', ['session_id' => 'dev-1']);
        $this->assertDatabaseHas('call_sessions', ['session_id' => 'dev-2']);
        $this->assertSame(MemberCallStatus::InCall, $this->callStatusOf($channel, $user->id));
    }

    public function test_heartbeat_persists_screen_sharing_and_exposes_it_in_participants(): void
    {
        Event::fake([CallScreenShareStarted::class, CallScreenShareStopped::class]);

        $channel = $this->makeChannel();
        $sharer = $this->makeUser();
        $viewer = $this->makeUser();
        $this->addMember($channel, $sharer, MembershipStatus::Member, MemberCallStatus::InCall);
        $this->addMember($channel, $viewer, MembershipStatus::Member, MemberCallStatus::InCall);
        $call = $this->makeActiveCall($channel, $sharer);
        $this->service->accept($sharer, $channel->id, 'sharer-session');

        $this->presence->heartbeat($sharer, $channel->id, 'sharer-session', true);

        $this->assertDatabaseHas('call_sessions', [
            'call_id' => $call->call_id,
            'session_id' => 'sharer-session',
            'screen_sharing' => true,
        ]);
        $this->assertTrue((bool) $this->service->participants($channel->id)->firstWhere('users_id', $sharer->id)?->screen_sharing);
        Event::assertDispatched(CallScreenShareStarted::class);

        $this->presence->heartbeat($sharer, $channel->id, 'sharer-session', false);
        Event::assertDispatched(CallScreenShareStopped::class);
    }

    public function test_solo_call_is_kept_until_understaffed_ttl_then_ended(): void
    {
        Event::fake([CallEnded::class]);

        $channel = $this->makeChannel();
        $user = $this->makeUser();
        $this->addMember($channel, $user);
        $this->service->create($user, $channel->id);
        $call = Call::query()->where('channel_id', $channel->id)->active()->firstOrFail();

        $this->assertNotNull($call->understaffed_at);

        $this->travel(CallService::UNDERSTAFFED_TTL_SECONDS - 30)->seconds();
        $this->assertSame(0, $this->service->reapStaleCalls());
        $this->assertSame(CallStatus::Active, $call->fresh()->status);

        $this->travel(60)->seconds();
        $this->assertSame(1, $this->service->reapStaleCalls());
        $this->assertSame(CallStatus::Ended, $call->fresh()->status);
        Event::assertDispatched(CallEnded::class);
    }

    public function test_second_participant_clears_understaffed_timer_until_call_is_solo_again(): void
    {
        $channel = $this->makeChannel();
        $initiator = $this->makeUser();
        $joiner = $this->makeUser();
        $this->addMember($channel, $initiator, MembershipStatus::Admin);
        $this->addMember($channel, $joiner);
        $this->service->create($initiator, $channel->id);

        $this->travel(CallService::UNDERSTAFFED_TTL_SECONDS - 30)->seconds();
        $this->service->accept($joiner, $channel->id);

        $call = Call::query()->where('channel_id', $channel->id)->active()->firstOrFail();
        $this->assertNull($call->understaffed_at);

        $this->service->leave($joiner, $channel->id);
        $this->assertNotNull($call->fresh()->understaffed_at);
        $this->assertSame(CallStatus::Active, $call->fresh()->status);

        $this->travel(CallService::UNDERSTAFFED_TTL_SECONDS + 5)->seconds();
        $this->assertSame(1, $this->service->reapStaleCalls());
        $this->assertSame(CallStatus::Ended, $call->fresh()->status);
    }

    /** Сессия, записанная heartbeat-ом после завершения звонка (гонка с выходом), убирается командой уборки. */
    public function test_reaper_removes_stale_sessions_of_ended_calls(): void
    {
        $channel = $this->makeChannel();
        $user = $this->makeUser();
        $this->addMember($channel, $user);
        $this->service->create($user, $channel->id);
        $call = Call::query()->where('channel_id', $channel->id)->active()->firstOrFail();
        $call->update(['status' => CallStatus::Ended]);

        CallSession::query()->where('call_id', $call->call_id)->delete();
        CallSession::create(['call_id' => $call->call_id, 'session_id' => 'orphan', 'user_id' => $user->id,
            'channel_id' => $channel->id, 'last_seen_at' => now()->subSeconds(CallSession::STALE_SECONDS + 10)]);
        CallSession::create(['call_id' => $call->call_id, 'session_id' => 'fresh', 'user_id' => $user->id,
            'channel_id' => $channel->id, 'last_seen_at' => now()]);

        $this->artisan('calls:reap-stale')->assertSuccessful();

        $this->assertDatabaseMissing('call_sessions', ['session_id' => 'orphan']);
        // Свежую не трогаем: по ней звонок ещё может восстановиться (restoreRecentlyEndedCall).
        $this->assertDatabaseHas('call_sessions', ['session_id' => 'fresh']);
    }

    /** @return array{0: Channel, 1: User, 2: User} */
    private function privateChannel(): array
    {
        $channel = $this->makeChannel(['status' => ChannelType::Private]);
        $caller = $this->makeUser();
        $peer = $this->makeUser();
        $this->addMember($channel, $caller, MembershipStatus::Admin);
        $this->addMember($channel, $peer, MembershipStatus::Admin);

        return [$channel, $caller, $peer];
    }

    private function makeSession(Call $call, int $userId, string $sessionId, ?\DateTimeInterface $lastSeenAt = null): CallSession
    {
        return CallSession::create([
            'call_id' => $call->call_id,
            'user_id' => $userId,
            'channel_id' => $call->channel_id,
            'session_id' => $sessionId,
            'last_seen_at' => $lastSeenAt ?? now(),
        ]);
    }

    private function callStatusOf(Channel $channel, int $userId): MemberCallStatus
    {
        return ChannelMember::where('channels_id', $channel->id)->where('users_id', $userId)->value('call_status');
    }

    private function assertRejectedWith(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail("Ожидалась ApiException со статусом {$status}");
        } catch (ApiException $e) {
            $this->assertSame($status, $e->status());
        }
    }
}
