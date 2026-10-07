<?php

namespace App\Services\Servers;

use App\Enums\CallLeaveOutcome;
use App\Enums\CallStatus;
use App\Enums\ServerChannelKind;
use App\Enums\ServerPermission;
use App\Events\ServerVoiceParticipantJoined;
use App\Events\ServerVoiceParticipantLeft;
use App\Exceptions\ApiException;
use App\Models\Conversations\Call;
use App\Models\Conversations\CallSession;
use App\Models\Servers\Server;
use App\Models\Servers\ServerChannel;
use App\Models\Servers\ServerMember;
use App\Models\User;
use App\Services\Conversations\CallPresenceService;
use App\Services\Conversations\CallScreenPreviewService;
use App\Services\Conversations\SignalingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Голосовые каналы сервера: вход/выход без звонка-приглашения — как в Discord, кликнул
 * и уже в канале. Переиспользует Call/CallSession (см. CallService), но без ring/accept/
 * decline и без ChannelMember: присутствие целиком выводится из CallSession.
 */
class ServerChannelCallService
{
    public function __construct(
        private readonly CallPresenceService $presence,
        private readonly CallScreenPreviewService $screenPreviews,
        private readonly ServerRoleService $roles,
        private readonly ServerChannelAccess $access,
    ) {}

    public function join(User $user, ServerChannel $channel, ?string $sessionId): array
    {
        $this->assertVoiceChannel($channel);

        // Как в Discord: в голосе можно быть только в одном канале. Без этого старая сессия
        // (сбой leave, перезагрузка, другое устройство) оставалась, и человек «сидел» в двух
        // каналах сразу, пока её не выметет уборка по таймауту.
        $this->leaveOtherVoiceChannels($user, (int) $channel->id);

        $call = Call::activeInServerChannel((int) $channel->id);

        if (! $call) {
            $call = Call::create([
                'call_id' => Str::uuid()->toString(),
                'server_channel_id' => $channel->id,
                'initiator_id' => $user->id,
                'status' => CallStatus::Active,
            ]);
        }

        if ($sessionId) {
            $this->presence->registerServerChannelSession($call, $user, $sessionId);
        }

        broadcast(new ServerVoiceParticipantJoined($call, $user))->toOthers();

        return [...$this->callPayload($call), ...$this->voiceRestrictions($user, $channel)];
    }

    public function leave(User $user, ServerChannel $channel, ?string $sessionId): CallLeaveOutcome
    {
        $call = Call::activeInServerChannel((int) $channel->id);

        if (! $call) {
            return CallLeaveOutcome::Left;
        }

        $stillConnected = $this->presence->disconnectServerChannelDevice($call, $user, $sessionId);

        if (! $stillConnected) {
            broadcast(new ServerVoiceParticipantLeft($call, $user))->toOthers();
        }

        if ($this->activeParticipantCount((int) $channel->id) === 0) {
            $call->update(['status' => CallStatus::Ended]);

            return CallLeaveOutcome::CallEnded;
        }

        return $stillConnected ? CallLeaveOutcome::StillConnectedElsewhere : CallLeaveOutcome::Left;
    }

    /** Все сессии пользователя в других голосовых каналах серверов — с «вышел» остальным и закрытием пустого звонка. */
    private function leaveOtherVoiceChannels(User $user, int $keepChannelId): void
    {
        $otherChannelIds = CallSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('server_channel_id')
            ->where('server_channel_id', '!=', $keepChannelId)
            ->distinct()
            ->pluck('server_channel_id');

        foreach ($otherChannelIds as $channelId) {
            $other = ServerChannel::query()->find($channelId);
            if ($other) {
                $this->leave($user, $other, null);
            }
        }
    }

    /** @return array{active: bool, superseded: bool, call_id?: string} */
    public function heartbeat(User $user, ServerChannel $channel, string $sessionId, bool $screenSharing): array
    {
        // Пинг из канала, откуда человек уже ушёл в другой голосовой (другое устройство/вкладка
        // с устаревшим состоянием), не должен воскрешать там сессию — иначе снова «два канала».
        // Отвечаем «вытеснен»: клиент выходит локально.
        $hasSessionHere = CallSession::query()
            ->where('user_id', $user->id)
            ->where('server_channel_id', $channel->id)
            ->where('session_id', $sessionId)
            ->exists();
        $inOtherChannel = CallSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('server_channel_id')
            ->where('server_channel_id', '!=', $channel->id)
            ->exists();
        if (! $hasSessionHere && $inOtherChannel) {
            return ['active' => false, 'superseded' => true];
        }

        // Ограничения голоса и в пинге: если событие ServerVoiceModerated потерялось (переподключение
        // сокета), клиент всё равно применит мьют модератора/отсутствие права «Говорить» за ≤15 с.
        return [
            ...$this->presence->heartbeatServerChannel($user, (int) $channel->id, $sessionId, $screenSharing),
            ...$this->voiceRestrictions($user, $channel),
        ];
    }

    /**
     * @return array{can_speak: bool, voice_muted: bool, voice_deafened: bool}
     *
     * can_speak — право «Говорить» на этом канале; без него человек входит заглушённым, как в Discord.
     */
    public function voiceRestrictions(User $user, ServerChannel $channel): array
    {
        // Те же права, что проверила ServerChannelPolicy::call в этом запросе (запомнены в ServerChannelAccess).
        $access = $this->access->for($user, $channel);

        return [
            'can_speak' => $access->allows(ServerPermission::SPEAK),
            'voice_muted' => (bool) $access->member?->voice_muted,
            'voice_deafened' => (bool) $access->member?->voice_deafened,
        ];
    }

    /** @return Collection<int, CallSession> кто сейчас в голосовом канале, по одной строке на пользователя */
    public function participants(ServerChannel $channel): Collection
    {
        $this->presence->pruneStaleServerChannelSessions(collect([(int) $channel->id]));

        $sessions = CallSession::query()
            ->where('server_channel_id', $channel->id)
            ->with('user:id,login,name,email,avatar,banner,banner_color,updated_at')
            ->get()
            ->unique('user_id')
            ->values();

        // Метка последнего кадра превью демонстрации — так же, как у ЛС/группы
        // (CallParticipantResource), только там она уже лежит на модели ChannelMember,
        // а тут ChannelMember нет, поэтому считаем здесь и навешиваем динамическим
        // свойством (тот же приём, что active_participants в ServerChannelController).
        $callId = $sessions->first()?->call_id;
        if ($callId) {
            foreach ($sessions as $session) {
                $session->screen_preview_at = $this->screenPreviews->lastFrameTime($callId, (int) $session->user_id);
            }
        }

        // Мьют/без звука от модератора и «приоритетный режим» — для иконок и приглушения остальных.
        $ownerId = (int) Server::query()->whereKey($channel->server_id)->value('owner_id');
        $members = ServerMember::query()
            ->where('server_id', $channel->server_id)
            ->whereIn('user_id', $sessions->pluck('user_id'))
            ->with('roles')
            ->get()
            ->keyBy('user_id');
        foreach ($sessions as $session) {
            $member = $members->get($session->user_id);
            $session->display_name = $member?->nickname;
            $session->voice_muted = (bool) $member?->voice_muted;
            $session->voice_deafened = (bool) $member?->voice_deafened;
            $session->priority_speaker = (int) $session->user_id === $ownerId
                || ($member !== null && ServerPermission::has($this->roles->basePermissions($member), ServerPermission::PRIORITY_SPEAKER));
        }

        return $sessions;
    }

    /** @return Collection<int, int> кто сейчас реально в голосовом канале — источник для SignalingService */
    public function activeParticipantUserIds(int $serverChannelId): Collection
    {
        return CallSession::query()
            ->where('server_channel_id', $serverChannelId)
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($userId) => (int) $userId);
    }

    private function activeParticipantCount(int $serverChannelId): int
    {
        return CallSession::query()
            ->where('server_channel_id', $serverChannelId)
            ->distinct('user_id')
            ->count('user_id');
    }

    private function assertVoiceChannel(ServerChannel $channel): void
    {
        if ($channel->kind !== ServerChannelKind::Voice) {
            throw new ApiException('Это не голосовой канал', 422);
        }
    }

    private function callPayload(Call $call): array
    {
        return [
            'call_id' => $call->call_id,
            'server_channel_id' => $call->server_channel_id,
            'initiator_id' => $call->initiator_id,
            'status' => $call->status->value,
            'type' => 'voice',
            // Момент, когда звонок в канале реально начался (первый вошедший), а не когда
            // именно этот пользователь подключился — таймер в UI должен идти "с начала
            // разговора", не сбрасываясь у каждого нового подключившегося со своей отметки.
            'started_at' => $call->created_at?->toIso8601String(),
        ];
    }
}
