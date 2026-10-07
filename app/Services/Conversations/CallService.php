<?php

namespace App\Services\Conversations;

use App\Data\CallStart;
use App\Enums\CallLeaveOutcome;
use App\Enums\CallStatus;
use App\Enums\ChannelType;
use App\Enums\MemberCallStatus;
use App\Events\CallCreated;
use App\Events\CallEnded;
use App\Events\CallParticipantJoined;
use App\Events\CallParticipantLeft;
use App\Events\CallSessionSuperseded;
use App\Events\PrivateCallCreated;
use App\Exceptions\ApiException;
use App\Models\Conversations\Call;
use App\Models\Conversations\Channel;
use App\Models\Conversations\ChannelMember;
use App\Models\User;
use App\Services\Account\PushNotificationService;
use App\Services\Servers\ServerChannelCallService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Жизненный цикл звонка: начать, принять, отклонить, выйти и завершить, если никого не осталось
 * или в звонке меньше двух человек дольше 10 минут.
 * Что пользователь состоит в канале, проверяет ChannelPolicy::call до вызова сервиса.
 */
class CallService
{
    /** Только что созданный пустой звонок не завершаем: инициатор мог ещё не успеть в него зайти. */
    private const EMPTY_CALL_GRACE_SECONDS = 5;

    /** Один человек в звонке — ждём, вдруг второй вернётся; потом завершаем. */
    public const UNDERSTAFFED_TTL_SECONDS = 600;

    public function __construct(
        private readonly CallPresenceService $presence,
        private readonly CallScreenPreviewService $screenPreviews,
        private readonly CallMessageService $messages,
        private readonly ServerChannelCallService $serverChannelCalls,
        private readonly PushNotificationService $push,
    ) {}

    public function create(User $user, int $channelId): CallStart
    {
        [$channel, $call, $created] = DB::transaction(function () use ($user, $channelId) {
            // Lock the parent even when no call exists yet; locking an empty result is insufficient.
            $channel = Channel::query()->whereKey($channelId)->lockForUpdate()->firstOrFail();
            $this->assertPeerAllowed($user, $channel->id);
            $existingCall = Call::activeIn($channel->id);
            if ($existingCall) {
                return [$channel, $existingCall, false];
            }

            $call = Call::create([
                'call_id' => Str::uuid()->toString(),
                'channel_id' => $channel->id,
                'initiator_id' => $user->id,
                'status' => CallStatus::Active,
                'understaffed_at' => now(),
            ]);

            return [$channel, $call, true];
        }, 3);

        if (! $created) {
            $this->leaveOtherCalls($user, $channel->id, $call->call_id);

            return new CallStart($this->callPayload($call, $user), created: false);
        }

        $this->leaveOtherCalls($user, $channel->id, $call->call_id);
        $this->setMemberCallStatus($channel->id, $user->id, MemberCallStatus::InCall);

        $recipients = $this->otherMembers($channel->id, (int) $user->id);
        $silentUserIds = $this->doNotDisturbIds($recipients);
        $payload = [...$this->callPayload($call, $user), 'silent_user_ids' => $silentUserIds];

        $this->messages->postStartMessage($call, $user);
        broadcast(new CallCreated($payload));

        $ringing = $this->ring($user, $recipients, $silentUserIds, $payload);
        $this->pushIncomingCall($user, $call, $ringing, $silentUserIds);

        return new CallStart($payload, created: true);
    }

    /** @return Collection<int, ChannelMember> */
    private function otherMembers(int $channelId, int $exceptUserId): Collection
    {
        return ChannelMember::query()
            ->where('channels_id', $channelId)
            ->where('users_id', '!=', $exceptUserId)
            ->active()
            ->whereHas('user')
            ->with('user:id,login,email,presence')
            ->get();
    }

    /**
     * В «не беспокоить» звонок у человека не звонит, а остальные видят, кто молчит.
     * Невидимка скрывает присутствие, но входящие звонки получает.
     *
     * @param  Collection<int, ChannelMember>  $members
     * @return int[]
     */
    private function doNotDisturbIds(Collection $members): array
    {
        return $members
            ->filter(fn (ChannelMember $member) => $member->user->presence === 'dnd')
            ->map(fn (ChannelMember $member) => (int) $member->users_id)
            ->values()
            ->all();
    }

    /**
     * Звонок у каждого, кроме «не беспокоить» и заблокированных в любую сторону.
     *
     * @param  Collection<int, ChannelMember>  $recipients
     * @param  int[]  $silentUserIds
     * @return int[] у кого зазвонило
     */
    private function ring(User $caller, Collection $recipients, array $silentUserIds, array $payload): array
    {
        $ringing = [];

        foreach ($recipients as $member) {
            $recipientId = (int) $member->users_id;

            if (! in_array($recipientId, $silentUserIds, true) && ! $caller->isBlockedWith($recipientId)) {
                broadcast(new PrivateCallCreated($payload, $recipientId));
                $ringing[] = $recipientId;
            }
        }

        return $ringing;
    }

    /**
     * Пуш «входящий звонок» тем же, у кого звонит в приложении: дойдёт и при закрытой вкладке.
     *
     * @param  int[]  $ringing
     * @param  int[]  $silentUserIds
     */
    private function pushIncomingCall(User $caller, Call $call, array $ringing, array $silentUserIds): void
    {
        $avatar = User::getAvatarUrl($caller->avatar, $caller->updated_at?->toISOString());

        $this->push->sendToUsers($ringing, [
            'title' => 'Входящий звонок',
            'body' => ((string) ($caller->name ?? $caller->login)).' звонит вам',
            'url' => "/channels/{$call->channel_id}",
            'tag' => "call-{$call->call_id}",
            'icon' => $avatar,
            'kind' => 'call',
            'call_id' => $call->call_id,
            'channel_id' => $call->channel_id,
            'initiator_id' => $caller->id,
            'initiator_login' => $caller->login ?? $caller->email,
            'initiator_avatar' => $avatar,
            'silent_user_ids' => $silentUserIds,
        ]);
    }

    /**
     * $expectedCallId — звонок, который клиент принимает. Если в канале уже другой (тот закончился,
     * начался новый), не пускаем: клиент войдёт в новый звонок сам, когда покажет его человеку.
     */
    public function accept(User $user, int $channelId, ?string $sessionId = null, ?string $expectedCallId = null): void
    {
        $this->assertPeerAllowed($user, $channelId);

        $call = Call::activeIn($channelId) ?? throw new ApiException('В канале нет активного звонка', 404);
        if ($expectedCallId !== null && $expectedCallId !== (string) $call->call_id) {
            throw new ApiException('Этот звонок уже закончился — в чате идёт новый', 409);
        }

        // Уже отмечен «в звонке» — это не первый вход, а повтор: пропущенные пинги, ручной
        // retry или клиент молча перезагрузился (например, после деплоя фронта) и зовёт
        // accept заново, не послав leave. Остальные участники в таком случае думают, что
        // P2P-соединение с этим человеком всё ещё живое, хотя оно висит мёртвым грузом:
        // на другом конце уже новая вкладка с новым RTCPeerConnection. broadcastWith::resumed
        // просит их клиент прибить старое соединение и пересоздать его принудительно.
        $wasAlreadyInCall = ChannelMember::query()
            ->where('channels_id', $channelId)
            ->where('users_id', $user->id)
            ->where('call_status', MemberCallStatus::InCall)
            ->exists();

        $this->leaveOtherCalls($user, $channelId, $call->call_id, $sessionId);
        $this->setMemberCallStatus($channelId, $user->id, MemberCallStatus::InCall);

        if ($sessionId) {
            $this->presence->registerSession($call, $user, $sessionId);
        }

        // Ответил кто-то кроме инициатора — звонок состоялся, а не пропущен.
        if ((int) $user->id !== (int) $call->initiator_id && ! $call->answered) {
            $call->update(['answered' => true]);
        }

        broadcast(new CallParticipantJoined($call, $user, resumed: $wasAlreadyInCall))->toOthers();

        $this->syncUnderstaffedState($call->fresh() ?? $call);
    }

    public function decline(User $user, int $channelId): void
    {
        $this->setMemberCallStatus($channelId, $user->id, MemberCallStatus::Declined);
    }

    /** Без id сессии (старый клиент или принудительный выход) снимаются все устройства пользователя. */
    public function leave(User $user, int $channelId, ?string $sessionId = null): CallLeaveOutcome
    {
        $call = Call::activeIn($channelId);
        $stillConnected = $call !== null && $this->presence->disconnectDevice($call, $user, $sessionId);

        if (! $stillConnected) {
            $this->setMemberCallStatus($channelId, $user->id, MemberCallStatus::Idle);

            if ($call) {
                broadcast(new CallParticipantLeft($call, $user))->toOthers();
            }
        }

        if ($call && $this->activeParticipantCount($channelId) === 0) {
            $this->endCall($call);

            return CallLeaveOutcome::CallEnded;
        }

        if ($call) {
            $this->syncUnderstaffedState($call);
        }

        return $stillConnected ? CallLeaveOutcome::StillConnectedElsewhere : CallLeaveOutcome::Left;
    }

    /** @return Collection<int, ChannelMember> участники звонка с флагом демонстрации экрана и меткой превью */
    public function participants(int $channelId): Collection
    {
        $members = ChannelMember::query()
            ->where('channels_id', $channelId)
            ->where('call_status', MemberCallStatus::InCall)
            ->with('user:id,login,name,email,avatar,updated_at')
            ->get();

        $call = Call::activeIn($channelId);

        if (! $call) {
            return $members;
        }

        $sharingUserIds = $this->presence->screenSharingUserIds($call->call_id);

        foreach ($members as $member) {
            $member->screen_sharing = $sharingUserIds->contains((int) $member->users_id);
            $member->screen_preview_at = $member->screen_sharing
                ? $this->screenPreviews->lastFrameTime($call->call_id, (int) $member->users_id)
                : null;
        }

        return $members;
    }

    /** @return Collection<int, Call> активные звонки в каналах пользователя вместе с участниками */
    public function activeCallsForUser(User $user): Collection
    {
        $channelIds = $user->activeChannelIds();

        // Список опрашивается каждые ~5 секунд, поэтому призраков выметаем здесь, без отдельного крона.
        $this->presence->pruneStaleSessions($channelIds);

        $calls = Call::query()
            ->whereIn('channel_id', $channelIds)
            ->active()
            ->latest()
            ->get()
            ->unique('channel_id');

        $participantCounts = ChannelMember::query()
            ->whereIn('channels_id', $calls->pluck('channel_id'))
            ->where('call_status', MemberCallStatus::InCall)
            ->selectRaw('channels_id, COUNT(*) as total')
            ->groupBy('channels_id')
            ->pluck('total', 'channels_id');

        return $calls
            ->reject(fn (Call $call) => $this->endCallIfUnderstaffed($call, (int) ($participantCounts[$call->channel_id] ?? 0)))
            ->map(function (Call $call) {
                $call->active_participants = $this->participants($call->channel_id);

                return $call;
            })
            ->values();
    }

    /** Для планировщика: завершает звонки без живых участников, даже если все клиенты упали молча. */
    public function reapStaleCalls(): int
    {
        $calls = Call::query()->active()->oldest()->get();

        if ($calls->isEmpty()) {
            return 0;
        }

        // Звонок в голосовом канале сервера хранит server_channel_id вместо channel_id (см.
        // ServerChannelCallService) — у него нет ChannelMember, присутствие только через
        // CallSession, поэтому и чистка стухших сессий, и подсчёт участников для него отдельные.
        $this->presence->pruneStaleSessions($calls->pluck('channel_id')->filter()->unique());
        $this->presence->pruneStaleServerChannelSessions($calls->pluck('server_channel_id')->filter()->unique());

        $ended = 0;

        foreach ($calls as $call) {
            $call = $call->fresh();

            if (! $call || $call->status !== CallStatus::Active) {
                continue;
            }

            $participantCount = $call->channel_id !== null
                ? $this->activeParticipantCount($call->channel_id)
                : $this->serverChannelCalls->activeParticipantUserIds($call->server_channel_id)->count();

            if ($this->endCallIfUnderstaffed($call, $participantCount)) {
                $ended++;
            }
        }

        return $ended;
    }

    /** Переносит пользователя в звонок $keepCallId: из остальных звонков он выходит, опустевшие завершаются. */
    private function leaveOtherCalls(User $user, int $keepChannelId, string $keepCallId, ?string $winningSessionId = null): void
    {
        $channelIds = ChannelMember::query()
            ->where('users_id', $user->id)
            ->where('channels_id', '!=', $keepChannelId)
            ->where('call_status', MemberCallStatus::InCall)
            ->pluck('channels_id')
            ->merge($this->presence->channelIdsWithSessions($user, exceptChannelId: $keepChannelId))
            ->map(fn ($channelId) => (int) $channelId)
            ->unique();

        foreach ($channelIds as $channelId) {
            $call = Call::activeIn($channelId);

            if ($call?->call_id === $keepCallId) {
                continue;
            }

            $removedSessions = $this->presence->disconnectFromChannel($channelId, $user);
            $wasInCall = ChannelMember::query()
                ->where('channels_id', $channelId)
                ->where('users_id', $user->id)
                ->where('call_status', MemberCallStatus::InCall)
                ->update(['call_status' => MemberCallStatus::Idle]);

            if (! $call) {
                continue;
            }

            if ($removedSessions > 0 || $wasInCall > 0) {
                broadcast(new CallParticipantLeft($call, $user))->toOthers();

                if ($winningSessionId) {
                    broadcast(new CallSessionSuperseded((int) $user->id, $call->call_id, $winningSessionId));
                }
            }

            if ($this->activeParticipantCount($channelId) === 0) {
                $this->endCall($call);
            } else {
                $this->syncUnderstaffedState($call);
            }
        }
    }

    private function endCall(Call $call): void
    {
        $call->update(['status' => CallStatus::Ended]);
        $this->messages->postSummary($call);
        broadcast(new CallEnded($call));
    }

    /** @return bool true, если звонок завершён */
    private function endCallIfUnderstaffed(Call $call, int $participantCount): bool
    {
        if ($participantCount >= 2) {
            $this->clearUnderstaffedAt($call);

            return false;
        }

        if ($participantCount === 0) {
            if ($call->created_at->diffInSeconds(now()) <= self::EMPTY_CALL_GRACE_SECONDS) {
                return false;
            }

            $this->endCall($call);

            return true;
        }

        if ($call->understaffed_at === null) {
            $call->forceFill(['understaffed_at' => now()])->save();

            return false;
        }

        if ($call->understaffed_at->diffInSeconds(now()) < self::UNDERSTAFFED_TTL_SECONDS) {
            return false;
        }

        $this->endCall($call);

        return true;
    }

    private function syncUnderstaffedState(Call $call): void
    {
        $count = $this->activeParticipantCount($call->channel_id);

        if ($count >= 2) {
            $this->clearUnderstaffedAt($call);

            return;
        }

        if ($call->understaffed_at === null) {
            $call->forceFill(['understaffed_at' => now()])->save();
        }
    }

    private function clearUnderstaffedAt(Call $call): void
    {
        if ($call->understaffed_at === null) {
            return;
        }

        $call->forceFill(['understaffed_at' => null])->save();
    }

    /**
     * В личном канале звонить можно только другу и без блокировки в любую сторону. Личный чат
     * остаётся и после удаления из друзей, поэтому проверяем в момент звонка. В беседах участие
     * определяет состав канала.
     */
    private function assertPeerAllowed(User $user, int $channelId): void
    {
        if (Channel::query()->whereKey($channelId)->value('status') !== ChannelType::Private) {
            return;
        }

        $peerId = (int) ChannelMember::query()
            ->where('channels_id', $channelId)
            ->where('users_id', '!=', $user->id)
            ->active()
            ->value('users_id');

        if ($peerId === 0) {
            return;
        }

        if ($user->isBlockedWith($peerId)) {
            throw new ApiException('Пользователь заблокирован', 403);
        }

        if (! $user->isFriend($peerId)) {
            throw new ApiException('Звонить можно только друзьям', 403);
        }
    }

    private function activeParticipantCount(int $channelId): int
    {
        return ChannelMember::query()
            ->where('channels_id', $channelId)
            ->where('call_status', MemberCallStatus::InCall)
            ->count();
    }

    private function setMemberCallStatus(int $channelId, int $userId, MemberCallStatus $callStatus): void
    {
        ChannelMember::query()
            ->where('channels_id', $channelId)
            ->where('users_id', $userId)
            ->update(['call_status' => $callStatus]);
    }

    private function callPayload(Call $call, User $user): array
    {
        return [
            'call_id' => $call->call_id,
            'channel_id' => $call->channel_id,
            'initiator_id' => $call->initiator_id,
            'initiator_login' => $user->login ?? $user->email,
            'status' => $call->status->value,
            'type' => 'voice',
        ];
    }
}
