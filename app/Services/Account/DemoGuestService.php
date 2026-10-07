<?php

namespace App\Services\Account;

use App\Data\DemoLoginResult;
use App\Data\SessionContext;
use App\Enums\CallStatus;
use App\Enums\ChannelType;
use App\Enums\FriendStatus;
use App\Enums\MembershipStatus;
use App\Enums\ServerChannelKind;
use App\Enums\ServerMembershipStatus;
use App\Enums\ServerPermission;
use App\Models\Conversations\Attachment;
use App\Models\Conversations\Call;
use App\Models\Conversations\CallSession;
use App\Models\Conversations\Channel;
use App\Models\Conversations\ChannelMember;
use App\Models\Conversations\Message;
use App\Models\Conversations\MessageReaction;
use App\Models\Servers\Server;
use App\Models\Servers\ServerChannel;
use App\Models\Servers\ServerMember;
use App\Models\Servers\ServerRole;
use App\Models\Social\Friend;
use App\Models\User;
use App\Services\Conversations\AttachmentService;
use App\Services\Conversations\CallScreenPreviewService;
use App\Services\Conversations\EncryptionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Демо-вход с лендинга: посетитель в один клик получает временный аккаунт с готовым окружением —
 * сервер с каналами и ролью, друзья, личный чат и беседа. Переписку ведут постоянные демо-друзья
 * (demo_kind = persona). Через TTL_HOURS гость удаляется вместе со всем, что успел создать.
 *
 * Гостю закрыто всё, через что можно достучаться до настоящих людей (см. RestrictDemoGuest),
 * поэтому его чаты — только с демо-друзьями.
 */
class DemoGuestService
{
    public const TTL_HOURS = 24;

    /** Сколько гость может загрузить в сумме: не даём забить диск и вытеснить чужие вложения. */
    public const UPLOAD_QUOTA_BYTES = 100 * 1024 * 1024;

    private const AVATAR_VERSION = 3;

    /** Общая иконка демо-серверов: префикс demo_ ServerService не удаляет при смене иконки гостем. */
    private const SERVER_ICON = 'demo_dayz_v1.jpg';

    /** Кадр демо-стрима живёт, пока жив сам гость: обновлять его некому. */
    private const STREAM_TTL_SECONDS = (self::TTL_HOURS + 1) * 3600;

    /** Ключ — имя файла аватара в resources/demo/avatars и часть логина. */
    private const PERSONAS = [
        'forestdump' => ['name' => 'forest dump', 'emoji' => '🐟', 'status' => 'лутаю Черногорск', 'color' => '#6b7280'],
        'lunitunz' => ['name' => 'LuniTunz', 'emoji' => '🔴', 'status' => 'стримлю DayZ', 'color' => '#e5533d'],
        'yasnova' => ['name' => 'Ya snova s vami', 'emoji' => '💜', 'status' => 'снова с вами', 'color' => '#d946ef'],
        'batislav' => ['name' => 'BATISLAV', 'emoji' => '🎧', 'status' => 'на Тисах', 'color' => '#c62828'],
    ];

    private const MODERATOR_PERMISSIONS = ServerPermission::DEFAULT
        | ServerPermission::MANAGE_MESSAGES
        | ServerPermission::MUTE_MEMBERS
        | ServerPermission::DEAFEN_MEMBERS
        | ServerPermission::MOVE_MEMBERS
        | ServerPermission::KICK_MEMBERS
        | ServerPermission::MANAGE_NICKNAMES
        | ServerPermission::VIEW_AUDIT_LOG;

    public function __construct(
        private readonly EncryptionService $encryption,
        private readonly SessionService $sessions,
        private readonly AttachmentService $attachments,
        private readonly UserDeletionService $deletion,
        private readonly CallScreenPreviewService $screenPreviews,
    ) {}

    public function start(SessionContext $context): DemoLoginResult
    {
        $personas = $this->ensurePersonas();
        // Первый пинг «я в сети» от гостя придёт не сразу, а список участников он увидит сразу.
        $this->keepPersonasOnline();

        [$guest, $server, $general] = DB::transaction(function () use ($personas) {
            $guest = $this->createGuest();
            $this->createPersonaFriendships($guest, $personas);
            [$server, $general] = $this->createServer($guest, $personas);
            $this->createDirectChat($guest, $personas['lunitunz']);
            $this->createGroupChat($guest, $personas);

            return [$guest, $server, $general];
        });

        return new DemoLoginResult($guest, $this->sessions->issueToken($guest, $context), (int) $server->id, (int) $general->id);
    }

    /**
     * Пока гость в сети, в сети и его демо-друзья — иначе демо выглядит вымершим.
     * Через toBase: обычный update тронул бы updated_at, а по нему у аватара строится ?v= для кэша.
     */
    public function keepPersonasOnline(): void
    {
        User::query()->where('demo_kind', User::DEMO_PERSONA)->toBase()->update(['last_online' => now()]);
    }

    public function pruneExpired(): int
    {
        $count = 0;

        User::query()
            ->where('demo_kind', User::DEMO_GUEST)
            ->where('created_at', '<', now()->subHours(self::TTL_HOURS))
            ->chunkById(50, function ($guests) use (&$count) {
                foreach ($guests as $guest) {
                    $this->deleteGuest($guest);
                    $count++;
                }
            });

        return $count;
    }

    public function deleteGuest(User $guest): void
    {
        DB::transaction(function () use ($guest) {
            // Чаты, где кроме гостя только демо-друзья, удаляем целиком: иначе переписка
            // демо-друзей копилась бы от каждого гостя.
            $channelIds = Channel::query()
                ->whereHas('members', fn ($query) => $query->where('users_id', $guest->id))
                ->whereDoesntHave('members.user', fn ($query) => $query->real())
                ->pluck('id');

            $this->deleteChannels($channelIds);

            Attachment::query()
                ->where('user_id', $guest->id)
                ->pluck('disk_path')
                ->each(fn (string $path) => $this->attachments->deleteByPath($path));

            // Сервер гостя удаляется здесь же: UserDeletionService убирает серверы владельца.
            $this->deletion->deleteUser($guest);
        });
    }

    /** @return array<string, User> ключ из PERSONAS => пользователь */
    public function ensurePersonas(): array
    {
        $personas = [];

        foreach (self::PERSONAS as $key => $persona) {
            $user = User::query()
                ->where('demo_kind', User::DEMO_PERSONA)
                ->where('email', $this->personaEmail($key))
                ->first()
                ?? $this->createPersona($key, $persona);

            $this->ensureAvatar($user, $key);
            $personas[$key] = $user;
        }

        return $personas;
    }

    /** @param  array{name: string, emoji: string, status: string, color: string}  $persona */
    private function createPersona(string $key, array $persona): User
    {
        $login = 'demo_'.$key;

        if (User::query()->where('login', $login)->exists()) {
            $login .= '_'.Str::lower(Str::random(4));
        }

        $user = new User([
            'name' => $persona['name'],
            'login' => $login,
            'email' => $this->personaEmail($key),
            'password' => Str::random(40),
            'date' => now(),
            'status_emoji' => $persona['emoji'],
            'status_text' => $persona['status'],
            'banner_color' => $persona['color'],
            'last_online' => now(),
        ]);
        $user->forceFill(['demo_kind' => User::DEMO_PERSONA, 'email_verified_at' => now()])->save();

        return $user;
    }

    /** Домен .invalid зарезервирован (RFC 2606): письмо на такой адрес не уйдёт никуда. */
    private function personaEmail(string $key): string
    {
        return "demo-{$key}@goidacord.invalid";
    }

    /**
     * Версия в имени файла: сменили картинки в resources/demo/avatars — поднимаем AVATAR_VERSION,
     * и у демо-друзей новый адрес аватара, который не застрянет в кэше браузера.
     */
    private function ensureAvatar(User $user, string $key): void
    {
        $file = "demo_{$key}_v".self::AVATAR_VERSION.'.jpg';
        $disk = Storage::disk('public');

        if (! $disk->exists('avatars/'.$file)) {
            $disk->put('avatars/'.$file, (string) file_get_contents(resource_path("demo/avatars/{$key}.jpg")));
        }

        if ($user->avatar !== $file) {
            if (is_string($user->avatar) && str_starts_with($user->avatar, 'demo_')) {
                $disk->delete('avatars/'.$user->avatar);
            }
            $user->forceFill(['avatar' => $file])->save();
        }
    }

    private function createGuest(): User
    {
        do {
            $login = 'guest_'.Str::lower(Str::random(6));
        } while (User::query()->where('login', $login)->exists());

        $guest = new User([
            'name' => 'Гость '.random_int(1000, 9999),
            'login' => $login,
            'email' => $login.'@demo.goidacord.invalid',
            'password' => Str::random(40),
            'date' => now(),
            'last_online' => now(),
        ]);
        $guest->forceFill(['demo_kind' => User::DEMO_GUEST, 'email_verified_at' => now()])->save();

        return $guest;
    }

    /** @param  array<string, User>  $personas */
    private function createPersonaFriendships(User $guest, array $personas): void
    {
        foreach ($personas as $persona) {
            Friend::create(['users_id' => $persona->id, 'friend_id' => $guest->id, 'status' => FriendStatus::Accepted]);
        }
    }

    /**
     * @param  array<string, User>  $friends
     * @return array{0: Server, 1: ServerChannel} сервер и канал, который открывается первым
     */
    private function createServer(User $guest, array $friends): array
    {
        $server = Server::create([
            'name' => 'DayZ',
            'description' => 'Демо-сервер: всё настоящее, пробуйте что угодно.',
            'icon' => $this->ensureServerIcon(),
            'owner_id' => $guest->id,
        ]);

        $everyone = ServerRole::create([
            'server_id' => $server->id,
            'name' => 'everyone',
            'permissions' => ServerPermission::DEFAULT,
            'is_default' => true,
        ]);
        $moderators = ServerRole::create([
            'server_id' => $server->id,
            'name' => 'Модераторы',
            'color' => '#23a559',
            'position' => 1,
            'permissions' => self::MODERATOR_PERMISSIONS,
            'hoist' => true,
            'mentionable' => true,
        ]);

        foreach ([$guest, ...array_values($friends)] as $user) {
            $member = ServerMember::create([
                'server_id' => $server->id,
                'user_id' => $user->id,
                'status' => ServerMembershipStatus::Member,
                'joined_at' => now(),
            ]);
            $member->roles()->attach($user->is($friends['forestdump']) ? [$everyone->id, $moderators->id] : [$everyone->id]);
        }

        $textCategory = $this->createServerChannel($server, 'Текстовые каналы', ServerChannelKind::Category, 0);
        $general = $this->createServerChannel($server, 'общий', ServerChannelKind::Text, 1, $textCategory, 'Болтаем обо всём');
        $screenshotsChannel = $this->createServerChannel($server, 'скриншоты', ServerChannelKind::Text, 2, $textCategory, 'Скрины, фотки, видео');
        $voiceCategory = $this->createServerChannel($server, 'Голосовые каналы', ServerChannelKind::Category, 3);
        $this->createServerChannel($server, 'Общий', ServerChannelKind::Voice, 4, $voiceCategory);
        $stream = $this->createServerChannel($server, 'DayZ', ServerChannelKind::Voice, 5, $voiceCategory);

        $this->postMessage($friends['forestdump'], $general, 'го вечером на сервер? вайп был', 190);
        $raidMessage = $this->postMessage($friends['batislav'], $general, 'я в деле, только сначала в зелёнку за лутом', 187);
        $this->postMessage($friends['yasnova'], $general, 'возьмите в пати, у меня топор и две банки фасоли 😅', 184);
        $this->postMessage($friends['forestdump'], $general, 'норм, на спавне найдём тебе чё-нить', 182);
        $streamMessage = $this->postMessage($friends['lunitunz'], $general, 'я уже на тисах, стримлю в голосовом, залетайте', 121);
        $heliMessage = $this->postMessage($friends['batislav'], $general, 'смотрю, за тобой вертолёт горит 😂', 118, $streamMessage);
        $this->postMessage($friends['lunitunz'], $general, 'это не я его сбил, он сам', 117);
        $this->postMessage($friends['yasnova'], $general, 'кто со мной в березино?', 12);
        $this->postMessage($friends['forestdump'], $general, 'через 10 мин буду', 9);

        $this->postMessage($friends['forestdump'], $screenshotsChannel, 'сюда кидаем скрины и клипы', 63);

        $this->addReactions($raidMessage, [$friends['yasnova'], $friends['lunitunz']], '🔥');
        $this->addReactions($streamMessage, [$friends['batislav'], $friends['forestdump']], '🎮');
        $this->addReactions($heliMessage, [$friends['forestdump'], $friends['lunitunz'], $friends['yasnova']], '😂');

        $this->startDemoStream($stream, $friends['lunitunz'], [$friends['batislav']]);

        return [$server, $general];
    }

    private function ensureServerIcon(): string
    {
        $disk = Storage::disk('public');
        if (! $disk->exists('server-icons/'.self::SERVER_ICON)) {
            $disk->put('server-icons/'.self::SERVER_ICON, (string) file_get_contents(resource_path('demo/server-icon.jpg')));
        }

        return self::SERVER_ICON;
    }

    /**
     * Демо-друг «стримит» DayZ в голосовом канале: сессия с флагом демонстрации и кадр превью.
     * Настоящего видео нет, поэтому last_seen_at — на весь срок жизни гостя, иначе уборка по
     * таймауту убрала бы стримера из канала; удаляется вместе с сервером гостя.
     *
     * @param  list<User>  $viewers
     */
    private function startDemoStream(ServerChannel $channel, User $streamer, array $viewers): void
    {
        $call = Call::create([
            'call_id' => Str::uuid()->toString(),
            'server_channel_id' => $channel->id,
            'initiator_id' => $streamer->id,
            'status' => CallStatus::Active,
        ]);

        foreach ([$streamer, ...$viewers] as $user) {
            CallSession::query()->create([
                'call_id' => $call->call_id,
                'session_id' => 'demo-'.$user->id,
                'user_id' => $user->id,
                'server_channel_id' => $channel->id,
                'last_seen_at' => now()->addSeconds(self::STREAM_TTL_SECONDS),
                'screen_sharing' => $user->is($streamer),
            ]);
        }

        $this->screenPreviews->storeFrameForServerChannel(
            $streamer,
            (int) $channel->id,
            (string) file_get_contents(resource_path('demo/stream-dayz.jpg')),
            self::STREAM_TTL_SECONDS,
        );
    }

    private function createServerChannel(
        Server $server,
        string $name,
        ServerChannelKind $kind,
        int $position,
        ?ServerChannel $category = null,
        ?string $topic = null,
    ): ServerChannel {
        return ServerChannel::create([
            'server_id' => $server->id,
            'category_id' => $category?->id,
            'name' => $name,
            'kind' => $kind,
            'topic' => $topic,
            'position' => $position,
        ]);
    }

    private function createDirectChat(User $guest, User $demoFriend): void
    {
        $channel = Channel::create(['name' => '', 'status' => ChannelType::Private]);

        foreach ([$guest, $demoFriend] as $user) {
            ChannelMember::create(['users_id' => $user->id, 'channels_id' => $channel->id, 'status' => MembershipStatus::Admin]);
        }

        $this->postMessage($demoFriend, $channel, 'йо 👋', 30);
        $this->postMessage($demoFriend, $channel, 'я демо-друг, так что звонить бесполезно, трубку не возьму 😅 заходи на сервер DayZ слева, я там стримлю', 29);
    }

    /** @param  array<string, User>  $friends */
    private function createGroupChat(User $guest, array $friends): void
    {
        $channel = Channel::create(['name' => 'Вылазка в субботу 🪖', 'status' => ChannelType::Group]);
        $organizer = $friends['forestdump'];

        foreach ([$organizer, $guest, $friends['yasnova'], $friends['batislav']] as $user) {
            ChannelMember::create([
                'users_id' => $user->id,
                'channels_id' => $channel->id,
                'status' => $user->is($organizer) ? MembershipStatus::Admin : MembershipStatus::Member,
            ]);
        }

        $creationMessage = new Message([
            'user_id' => $organizer->id,
            'channels_id' => $channel->id,
            'type' => 'system',
            'message' => "{$organizer->name} создал(а) беседу",
            'meta' => ['event' => 'channel_created', 'actor_name' => $organizer->name],
        ]);
        $this->backdateMessage($creationMessage, 300)->save();

        $this->postMessage($organizer, $channel, 'в субботу в 20:00 на сервер?', 299);
        $this->postMessage($friends['batislav'], $channel, 'я за, беру m4', 290);
        $this->postMessage($friends['yasnova'], $channel, 'на мне аптечки и еда 🥫', 285);
        $this->postMessage($organizer, $channel, 'тогда в 20:00 у черно', 280);
    }

    /** Сообщение с текстом, зашифрованным так же, как у обычных (MessageService::storeText). */
    private function postMessage(User $author, Channel|ServerChannel $target, string $text, int $minutesAgo, ?Message $replyTo = null): Message
    {
        $encrypted = $this->encryption->encrypt($text, (int) config('app.encryption_actual'));

        $message = new Message([
            'user_id' => $author->id,
            'channels_id' => $target instanceof Channel ? $target->id : null,
            'server_channel_id' => $target instanceof ServerChannel ? $target->id : null,
            'reply_to_id' => $replyTo?->id,
            'message' => $encrypted['data'],
            'key_id' => $encrypted['key_id'],
        ]);
        $this->backdateMessage($message, $minutesAgo)->save();

        return $message;
    }

    /** Заданные вручную created_at/updated_at Eloquent при save() не перезаписывает. */
    private function backdateMessage(Message $message, int $minutesAgo): Message
    {
        $message->created_at = now()->subMinutes($minutesAgo);
        $message->updated_at = $message->created_at;

        return $message;
    }

    /** @param  list<User>  $users */
    private function addReactions(Message $message, array $users, string $emoji): void
    {
        foreach ($users as $user) {
            MessageReaction::create(['message_id' => $message->id, 'user_id' => $user->id, 'emoji' => $emoji]);
        }
    }

    /** @param  Collection<int, int>  $channelIds */
    private function deleteChannels(Collection $channelIds): void
    {
        if ($channelIds->isEmpty()) {
            return;
        }

        $callIds = Call::query()->whereIn('channel_id', $channelIds)->pluck('call_id');
        DB::table('call_sessions')->whereIn('call_id', $callIds)->delete();
        DB::table('call_sessions')->whereIn('channel_id', $channelIds)->delete();
        Call::query()->whereIn('channel_id', $channelIds)->delete();

        Attachment::query()
            ->whereIn('message_id', Message::query()->whereIn('channels_id', $channelIds)->select('id'))
            ->pluck('disk_path')
            ->each(fn (string $path) => $this->attachments->deleteByPath($path));

        Message::query()->whereIn('channels_id', $channelIds)->delete();
        ChannelMember::query()->whereIn('channels_id', $channelIds)->delete();
        Channel::query()->whereKey($channelIds)->delete();
    }
}
