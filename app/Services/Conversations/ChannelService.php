<?php

namespace App\Services\Conversations;

use App\Data\ChannelCreation;
use App\Data\ChannelCreator;
use App\Data\CreateChannelData;
use App\Enums\ChannelType;
use App\Enums\MembershipStatus;
use App\Exceptions\ApiException;
use App\Models\Conversations\Channel;
use App\Models\Conversations\ChannelMember;
use App\Models\Conversations\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Каналы: личные чаты и беседы, их участники и роли.
 * Права на действие проверяет ChannelPolicy, здесь — только правила самих бесед.
 */
class ChannelService
{
    /** Поля профиля, которые нужны в списках участников и чатов. */
    private const MEMBER_USER_COLUMNS = [
        'id', 'name', 'login', 'avatar', 'banner', 'banner_color', 'presence', 'status_emoji',
        'status_text', 'game_status_text', 'game_status_synced_at', 'music_status_text',
        'last_online', 'updated_at',
    ];

    public function create(User $user, CreateChannelData $validated): ChannelCreation
    {
        $recipientIds = array_map('intval', $validated->recipients);

        foreach ($recipientIds as $recipientId) {
            if (! $user->isFriend($recipientId)) {
                throw new ApiException("Пользователь с ID {$recipientId} не является вашим другом", 403);
            }
        }

        return count($recipientIds) === 1
            ? $this->createPrivateChannel($user, $recipientIds[0], $validated)
            : $this->createGroupChannel($user, $recipientIds, $validated);
    }

    public function channelsFor(User $user): Collection
    {
        $channels = Channel::query()
            ->whereHas('members', fn ($query) => $query->where('users_id', $user->id)->active())
            ->with([
                'members.user' => fn ($query) => $query->select(self::MEMBER_USER_COLUMNS),
                'members.user.yandexMusicConnection',
            ])
            // Чаты сортируются по последнему сообщению: с кем недавно общались или звонили, те выше.
            ->withMax('messages', 'created_at')
            ->get()
            ->sortByDesc(fn (Channel $channel) => $channel->messages_max_created_at ?? '')
            ->values();

        $this->attachUnreadCounters($user, $channels);

        return $channels;
    }

    public function markRead(User $user, int $channelId): void
    {
        ChannelMember::query()
            ->where('channels_id', $channelId)
            ->where('users_id', $user->id)
            ->update(['last_read_message_id' => (int) Message::where('channels_id', $channelId)->max('id')]);
    }

    public function leave(User $user, int $channelId): void
    {
        $membership = $this->activeMembership($user->id, $channelId)
            ?? throw new ApiException('Вы не состоите в этом канале', 403);

        DB::transaction(function () use ($user, $channelId, $membership) {
            // Уходит админ — права переходят к любому оставшемуся участнику.
            if ($membership->status === MembershipStatus::Admin) {
                ChannelMember::query()
                    ->where('channels_id', $channelId)
                    ->where('users_id', '!=', $user->id)
                    ->active()
                    ->first()
                    ?->update(['status' => MembershipStatus::Admin]);
            }

            if (Channel::query()->whereKey($channelId)->value('status') === ChannelType::Group) {
                Message::createSystem($channelId, $user->id, 'member_left', ['actor_name' => $user->name], "{$user->name} вышел(а) из беседы");
            }

            $membership->update(['status' => MembershipStatus::Removed]);
        });
    }

    public function update(
        int $channelId,
        ?string $name = null,
        ?UploadedFile $avatarFile = null,
        bool $removeAvatar = false,
    ): Channel {
        $channel = Channel::query()->findOrFail($channelId);

        if ($channel->status !== ChannelType::Group) {
            throw ValidationException::withMessages(['channel' => ['Редактировать можно только групповую беседу']]);
        }

        if ($name !== null) {
            $name = trim($name);

            if ($name === '') {
                throw ValidationException::withMessages(['name' => ['Введите название беседы']]);
            }

            $channel->name = $name;
        }

        if (($removeAvatar || $avatarFile) && $channel->avatar) {
            $this->deleteAvatarFile((string) $channel->avatar);
            $channel->avatar = null;
        }

        if ($avatarFile) {
            $fileName = 'channel_'.$channel->id.'_'.Str::uuid().'.'.($avatarFile->getClientOriginalExtension() ?: 'jpg');
            $avatarFile->storeAs('avatars', $fileName, 'public');
            $channel->avatar = $fileName;
        }

        $channel->save();

        return $channel->fresh();
    }

    public function setMemberRole(User $actor, int $channelId, int $targetUserId, string $role): void
    {
        if ($targetUserId === (int) $actor->id) {
            throw ValidationException::withMessages(['role' => ['Нельзя изменить свою роль']]);
        }

        if (Channel::query()->whereKey($channelId)->value('status') !== ChannelType::Group) {
            throw ValidationException::withMessages(['role' => ['Роли доступны только в групповых беседах']]);
        }

        $membership = ChannelMember::query()
            ->where('channels_id', $channelId)
            ->where('users_id', $targetUserId)
            ->active()
            ->with('user:id,name')
            ->first()
            ?? throw new ApiException('Участник не найден', 404);

        $promote = $role === 'admin';
        $nextStatus = $promote ? MembershipStatus::Admin : MembershipStatus::Member;

        if ($membership->status === $nextStatus) {
            return;
        }

        $this->assertCanChangeRole($actor, $channelId, $targetUserId, $promote);

        $membership->update(['status' => $nextStatus]);

        $targetName = $membership->user->name ?? 'Участник';

        Message::createSystem(
            $channelId,
            $actor->id,
            $promote ? 'member_promoted' : 'member_demoted',
            ['actor_name' => $actor->name, 'target_id' => $targetUserId, 'target_name' => $targetName],
            $promote
                ? "{$actor->name} назначил(а) {$targetName} администратором"
                : "{$actor->name} снял(а) права администратора у {$targetName}",
        );
    }

    public function isMember(int $userId, int $channelId): bool
    {
        return $this->activeMembership($userId, $channelId) !== null;
    }

    public function isAdmin(int $userId, int $channelId): bool
    {
        return ChannelMember::query()
            ->where('channels_id', $channelId)
            ->where('users_id', $userId)
            ->where('status', MembershipStatus::Admin)
            ->exists();
    }

    /** @return Collection<int, int> */
    public function activeMemberIds(int $channelId): Collection
    {
        return ChannelMember::query()
            ->where('channels_id', $channelId)
            ->active()
            ->pluck('users_id')
            ->map(fn ($userId) => (int) $userId);
    }

    /** Участники беседы вместе с данными профиля. */
    public function activeMembers(Channel $channel): Collection
    {
        return ChannelMember::query()
            ->where('channels_id', $channel->id)
            ->active()
            ->whereHas('user')
            ->with([
                'user' => fn ($query) => $query->select(self::MEMBER_USER_COLUMNS),
                'user.yandexMusicConnection',
            ])
            ->get();
    }

    public function roleOf(User $user, Channel $channel): string
    {
        return $this->isAdmin((int) $user->id, (int) $channel->id) ? 'admin' : 'member';
    }

    /** Добавляет в беседу друзей из списка. Остальные id молча пропускаются. */
    public function addMembers(User $actor, Channel $channel, array $recipientIds): void
    {
        foreach ($recipientIds as $userId) {
            if ((int) $userId === (int) $actor->id || ! $actor->isFriend((int) $userId)) {
                continue;
            }

            ChannelMember::query()->updateOrCreate(
                ['channels_id' => $channel->id, 'users_id' => $userId],
                ['status' => MembershipStatus::Member],
            );
        }
    }

    public function kickMember(User $actor, Channel $channel, User $member): void
    {
        if ((int) $member->id === (int) $actor->id) {
            throw new ApiException('Нельзя исключить себя. Чтобы уйти, покиньте беседу', 422);
        }

        $membership = $this->activeMembership($member->id, $channel->id)
            ?? throw new ApiException('Участник не найден', 404);

        if ($membership->status === MembershipStatus::Admin) {
            throw new ApiException('Нельзя исключить админа беседы', 422);
        }

        $membership->update(['status' => MembershipStatus::Removed]);

        Message::createSystem(
            (int) $channel->id,
            (int) $actor->id,
            'member_kicked',
            ['actor_name' => $actor->name, 'target_id' => $member->id, 'target_name' => $member->name],
            "{$actor->name} исключил(а) {$member->name}",
        );
    }

    /** Создатель беседы — самый первый её админ. */
    public function resolveOwnerId(int $channelId): ?int
    {
        $ownerId = ChannelMember::query()
            ->where('channels_id', $channelId)
            ->where('status', MembershipStatus::Admin)
            ->orderBy('id')
            ->value('users_id');

        return $ownerId ? (int) $ownerId : null;
    }

    private function activeMembership(int $userId, int $channelId): ?ChannelMember
    {
        return ChannelMember::query()
            ->where('channels_id', $channelId)
            ->where('users_id', $userId)
            ->active()
            ->first();
    }

    /** Назначать и снимать админов может только создатель, а последнего админа снять нельзя. */
    private function assertCanChangeRole(User $actor, int $channelId, int $targetUserId, bool $promote): void
    {
        $ownerId = $this->resolveOwnerId($channelId);

        if ($ownerId !== (int) $actor->id) {
            throw new ApiException($promote
                ? 'Назначить администратора может только создатель беседы'
                : 'Снять администратора может только создатель беседы', 403);
        }

        if ($promote) {
            return;
        }

        if ($targetUserId === $ownerId) {
            throw new ApiException('Нельзя снять создателя беседы', 403);
        }

        $adminCount = ChannelMember::query()
            ->where('channels_id', $channelId)
            ->where('status', MembershipStatus::Admin)
            ->count();

        if ($adminCount <= 1) {
            throw ValidationException::withMessages(['role' => ['Нельзя снять последнего администратора']]);
        }
    }

    /** Непрочитанные и пропущенные звонки — два GROUP BY на весь список, а не запрос на каждый канал. */
    private function attachUnreadCounters(User $user, Collection $channels): void
    {
        if ($channels->isEmpty()) {
            return;
        }

        $channelIds = $channels->pluck('id');
        $unread = $this->countMessagesPastRead($user, $channelIds, 'text');
        $missed = $this->countMessagesPastRead($user, $channelIds, 'system', ['call_started', 'call_missed']);

        foreach ($channels as $channel) {
            $channel->unread_count = $unread[$channel->id] ?? 0;
            $channel->missed_calls = $missed[$channel->id] ?? 0;
        }
    }

    /**
     * @param  list<string>|null  $events
     * @return array<int, int>
     */
    private function countMessagesPastRead(User $user, Collection $channelIds, string $type, ?array $events = null): array
    {
        return Message::query()
            ->selectRaw('messages.channels_id as channel_id, COUNT(*) as aggregate')
            ->join('channels_members', fn ($join) => $join
                ->on('channels_members.channels_id', '=', 'messages.channels_id')
                ->where('channels_members.users_id', '=', $user->id))
            ->whereIn('messages.channels_id', $channelIds)
            ->where('messages.type', $type)
            ->where('messages.user_id', '!=', $user->id)
            ->whereRaw('messages.id > COALESCE(channels_members.last_read_message_id, 0)')
            ->when($events !== null, fn ($query) => $query->whereIn('messages.meta->event', $events))
            ->groupBy('messages.channels_id')
            ->pluck('aggregate', 'channel_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function createPrivateChannel(User $user, int $recipientId, CreateChannelData $validated): ChannelCreation
    {
        if ($recipientId === (int) $user->id) {
            throw new ApiException('Нельзя создать личный чат с самим собой', 400);
        }

        $existingChannel = $this->findPrivateChannel((int) $user->id, $recipientId);

        if ($existingChannel) {
            $this->rejoinPrivateChannel($existingChannel->id, (int) $user->id, $recipientId);
            $existingChannel->refresh()->load(['members.user' => fn ($query) => $query->select(self::MEMBER_USER_COLUMNS)]);

            return new ChannelCreation($existingChannel, created: false, rejoined: true);
        }

        return DB::transaction(function () use ($user, $recipientId, $validated) {
            // В личном чате оба участника админы, чтобы никто не мог исключить другого.
            [$channel, $members] = $this->createChannel(ChannelType::Private, $validated, [
                $user->id => MembershipStatus::Admin,
                $recipientId => MembershipStatus::Admin,
            ]);

            return new ChannelCreation($channel, created: true, creator: $this->creatorPayload($user),
                recipients: [$recipientId], members: $members, count: 2, isPrivate: true);
        });
    }

    /** @param  list<int>  $recipientIds */
    private function createGroupChannel(User $user, array $recipientIds, CreateChannelData $validated): ChannelCreation
    {
        return DB::transaction(function () use ($user, $recipientIds, $validated) {
            $memberStatuses = [$user->id => MembershipStatus::Admin]
                + array_fill_keys($recipientIds, MembershipStatus::Member);

            [$channel, $members] = $this->createChannel(ChannelType::Group, $validated, $memberStatuses);

            Message::createSystem($channel->id, $user->id, 'channel_created', ['actor_name' => $user->name], "{$user->name} создал(а) беседу");

            return new ChannelCreation($channel, created: true, creator: $this->creatorPayload($user),
                recipients: array_keys($memberStatuses), members: $members, count: count($memberStatuses), isPrivate: false);
        });
    }

    /**
     * @param  array<int, MembershipStatus>  $memberStatuses  id пользователя => статус участника
     * @return array{0: Channel, 1: list<ChannelMember>}
     */
    private function createChannel(ChannelType $type, CreateChannelData $validated, array $memberStatuses): array
    {
        $channel = Channel::create([
            'name' => $validated->name ?? '',
            'status' => $type,
            'avatar' => $validated->avatar ?? null,
        ]);

        $members = [];

        foreach ($memberStatuses as $userId => $status) {
            $members[] = ChannelMember::create([
                'users_id' => $userId,
                'channels_id' => $channel->id,
                'status' => $status,
            ]);
        }

        return [$channel, $members];
    }

    private function creatorPayload(User $user): ChannelCreator
    {
        return new ChannelCreator((int) $user->id, $user->name, MembershipStatus::Admin);
    }

    private function findPrivateChannel(int $firstUserId, int $secondUserId): ?Channel
    {
        return Channel::query()
            ->where('status', ChannelType::Private)
            ->whereHas('members', fn ($query) => $query->where('users_id', $firstUserId))
            ->whereHas('members', fn ($query) => $query->where('users_id', $secondUserId))
            ->with(['members' => fn ($query) => $query
                ->select('id', 'users_id', 'channels_id', 'status')
                ->with(['user' => fn ($query) => $query->select(self::MEMBER_USER_COLUMNS)])])
            ->select('id', 'name', 'status', 'avatar')
            ->first();
    }

    private function rejoinPrivateChannel(int $channelId, int $userId, int $recipientId): void
    {
        ChannelMember::query()
            ->where('channels_id', $channelId)
            ->whereIn('users_id', [$userId, $recipientId])
            ->where('status', MembershipStatus::Removed)
            ->update(['status' => MembershipStatus::Admin]);
    }

    private function deleteAvatarFile(string $fileName): void
    {
        if ($fileName === '' || $fileName === 'default.png') {
            return;
        }

        Storage::disk('public')->delete('avatars/'.basename($fileName));
    }
}
