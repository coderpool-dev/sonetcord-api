<?php

namespace App\Services\Servers;

use App\Data\PushNotificationData;
use App\Enums\ServerPermission;
use App\Events\ServerMentioned;
use App\Models\Conversations\Message;
use App\Models\Servers\Server;
use App\Models\Servers\ServerChannel;
use App\Models\Servers\ServerMember;
use App\Models\Servers\ServerRole;
use App\Models\User;
use App\Services\Account\PushNotificationService;
use App\Services\Conversations\NotificationMuteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Упоминания в каналах сервера как в Discord: <@userId>, <@&roleId>, @everyone, @here.
 * Текст сообщения хранится зашифрованным, поэтому упоминания разбираются до шифрования
 * и кладутся в messages.mentions — уже после проверки прав отправителя:
 *  - участника можно упомянуть всегда;
 *  - роль — если она «упоминаемая» или у отправителя есть MENTION_EVERYONE на канале;
 *  - @everyone / @here — только с MENTION_EVERYONE (без права остаются просто текстом).
 * Уведомление получают только те, кто видит канал, и не сам автор.
 */
class ServerMentionService
{
    private const EXCERPT_LENGTH = 140;

    public function __construct(
        private readonly ServerChannelPermissionResolver $resolver,
        private readonly PushNotificationService $push,
        private readonly NotificationMuteService $mutes,
    ) {}

    /** @return array{users: int[], roles: int[], everyone: bool, here: bool}|null null — упоминаний нет */
    public function resolve(User $sender, ServerChannel $channel, string $plain): ?array
    {
        preg_match_all('/<@(&?)(\d{1,20})>/', $plain, $tokens, PREG_SET_ORDER);
        $hasEveryone = (bool) preg_match('/(?<![\p{L}\p{N}_])@everyone(?![\p{L}\p{N}_])/u', $plain);
        $hasHere = (bool) preg_match('/(?<![\p{L}\p{N}_])@here(?![\p{L}\p{N}_])/u', $plain);

        if ($tokens === [] && ! $hasEveryone && ! $hasHere) {
            return null;
        }

        $userIds = collect($tokens)->filter(fn ($token) => $token[1] === '')->map(fn ($token) => (int) $token[2])->unique();
        $roleIds = collect($tokens)->filter(fn ($token) => $token[1] === '&')->map(fn ($token) => (int) $token[2])->unique();
        $canMentionAll = $this->canMentionEveryone($sender, $channel);

        $users = ServerMember::query()
            ->where('server_id', $channel->server_id)
            ->active()
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $roles = ServerRole::query()
            ->where('server_id', $channel->server_id)
            ->where('is_default', false)
            ->whereIn('id', $roleIds)
            ->get()
            ->filter(fn (ServerRole $role) => $role->mentionable || $canMentionAll)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $mentions = [
            'users' => $users,
            'roles' => $roles,
            'everyone' => $hasEveryone && $canMentionAll,
            'here' => $hasHere && $canMentionAll,
        ];

        return $users === [] && $roles === [] && ! $mentions['everyone'] && ! $mentions['here'] ? null : $mentions;
    }

    /** @param  array{users: int[], roles: int[], everyone: bool, here: bool}  $mentions */
    public function notify(Message $message, User $sender, ServerChannel $channel, array $mentions, string $plain): void
    {
        $server = $channel->server;
        $members = $this->activeMembers((int) $channel->server_id);

        // Тот, кто канал не видит, не должен узнать о нём из уведомления.
        $recipients = $this->mentionedMembers($members, $mentions, $sender)
            ->filter(fn (ServerMember $member) => $this->canSeeChannel($member, $server, $channel));

        if ($recipients->isEmpty()) {
            return;
        }

        $authorName = $members->firstWhere('user_id', $sender->id)?->nickname ?: (string) ($sender->name ?? $sender->login);
        $authorAvatar = User::getAvatarUrl($sender->avatar, $sender->updated_at?->toISOString());
        $excerpt = Str::limit($this->toLabels($members, (int) $channel->server_id, $plain), self::EXCERPT_LENGTH);

        foreach ($recipients as $member) {
            broadcast(new ServerMentioned(
                userId: (int) $member->user_id,
                serverId: (int) $server->id,
                serverName: (string) $server->name,
                serverChannelId: (int) $channel->id,
                channelName: (string) $channel->name,
                messageId: (int) $message->id,
                authorId: (int) $sender->id,
                authorName: $authorName,
                authorAvatar: $authorAvatar,
                excerpt: $excerpt,
                direct: in_array((int) $member->user_id, $mentions['users'], true),
            ));
        }

        $this->push->sendToUsers($this->withoutMuted($recipients, $server, $channel, $mentions['users']), PushNotificationData::fromArray([
            'title' => "{$authorName} в #{$channel->name}",
            'body' => $excerpt !== '' ? $excerpt : 'Вас упомянули',
            'url' => "/servers/{$server->id}/channels/{$channel->id}",
            'tag' => "mention-{$channel->id}",
            'icon' => $authorAvatar,
            'kind' => 'mention',
        ]));
    }

    /**
     * Кого сообщение упоминает: все, кто в сети (@here), лично или через роль. Автор — никогда.
     *
     * @param  Collection<int, ServerMember>  $members
     * @param  array{users: int[], roles: int[], everyone: bool, here: bool}  $mentions
     * @return Collection<int, ServerMember>
     */
    private function mentionedMembers(Collection $members, array $mentions, User $sender): Collection
    {
        return $members
            ->reject(fn (ServerMember $member) => (int) $member->user_id === (int) $sender->id)
            ->filter(fn (ServerMember $member) => $mentions['everyone']
                || ($mentions['here'] && $member->user?->isOnline())
                || in_array((int) $member->user_id, $mentions['users'], true)
                || $member->roles->pluck('id')->map(fn ($id) => (int) $id)->intersect($mentions['roles'])->isNotEmpty());
    }

    private function canSeeChannel(ServerMember $member, Server $server, ServerChannel $channel): bool
    {
        return (int) $server->owner_id === (int) $member->user_id
            || ServerPermission::has($this->resolver->effectivePermissions($member, $channel), ServerPermission::VIEW_CHANNELS);
    }

    /**
     * Кому слать пуш. Заглушённый сервер или канал молчит про @everyone, @here и роли,
     * но личное упоминание доходит — как в Discord.
     *
     * @param  Collection<int, ServerMember>  $recipients
     * @param  int[]  $directlyMentioned
     * @return int[]
     */
    private function withoutMuted(Collection $recipients, Server $server, ServerChannel $channel, array $directlyMentioned): array
    {
        $userIds = $recipients->map(fn (ServerMember $member) => (int) $member->user_id)->values()->all();
        $muted = array_diff(
            $this->mutes->mutedAmong($userIds, ['server' => (int) $server->id, 'server_channel' => (int) $channel->id]),
            $directlyMentioned,
        );

        return array_values(array_diff($userIds, $muted));
    }

    /** Токены упоминаний → «@ник» / «@роль» — для текста уведомления, который фронт показывает как есть. */
    private function toLabels(Collection $members, int $serverId, string $plain): string
    {
        $names = $members->mapWithKeys(fn (ServerMember $member) => [
            (int) $member->user_id => $member->nickname ?: ($member->user->name ?? $member->user->login ?? 'участник'),
        ]);
        $roleNames = ServerRole::query()->where('server_id', $serverId)->pluck('name', 'id');

        return (string) preg_replace_callback('/<@(&?)(\d{1,20})>/', function (array $match) use ($names, $roleNames) {
            $id = (int) $match[2];
            $name = $match[1] === '&' ? $roleNames->get($id) : $names->get($id);

            return '@'.($name ?? ($match[1] === '&' ? 'удалённая роль' : 'участник'));
        }, $plain);
    }

    private function canMentionEveryone(User $sender, ServerChannel $channel): bool
    {
        if ((int) $channel->server?->owner_id === (int) $sender->id) {
            return true;
        }

        $member = ServerMember::query()
            ->where('server_id', $channel->server_id)
            ->where('user_id', $sender->id)
            ->active()
            ->with('roles')
            ->first();

        return $member !== null
            && ServerPermission::has($this->resolver->effectivePermissions($member, $channel), ServerPermission::MENTION_EVERYONE);
    }

    /** @return Collection<int, ServerMember> */
    private function activeMembers(int $serverId): Collection
    {
        return ServerMember::query()
            ->where('server_id', $serverId)
            ->active()
            ->with(['roles', 'user'])
            ->get();
    }
}
