<?php

namespace App\Services\Account;

use App\Enums\ChannelType;
use App\Enums\FriendStatus;
use App\Enums\MembershipStatus;
use App\Enums\ServerChannelKind;
use App\Enums\ServerMembershipStatus;
use App\Enums\ServerPermission;
use App\Models\Conversations\Attachment;
use App\Models\Conversations\Call;
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
use App\Services\Conversations\EncryptionService;
use Illuminate\Http\Request;
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

    private const AVATAR_VERSION = 2;

    /** Ключ — имя файла аватара в resources/demo/avatars и часть логина. */
    private const PERSONAS = [
        'katya' => ['name' => 'Катя', 'emoji' => '🎧', 'status' => 'слушаю новый альбом', 'color' => '#ff8a5c'],
        'lyosha' => ['name' => 'Лёша', 'emoji' => '🎮', 'status' => 'кто в катку?', 'color' => '#5865f2'],
        'mila' => ['name' => 'Мила', 'emoji' => '🌿', 'status' => 'на даче до воскресенья', 'color' => '#23a559'],
        'anya' => ['name' => 'Аня', 'emoji' => '📚', 'status' => 'сессия, не отвлекайте', 'color' => '#f0b232'],
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
    ) {}

    /** @return array{user: User, token: string, server_id: int, channel_id: int} */
    public function start(Request $request): array
    {
        $personas = $this->ensurePersonas();
        // Первый пинг «я в сети» от гостя придёт не сразу, а список участников он увидит сразу.
        $this->keepPersonasOnline();

        [$guest, $server, $general] = DB::transaction(function () use ($personas) {
            $guest = $this->createGuest();
            $this->createPersonaFriendships($guest, $personas);
            [$server, $general] = $this->createServer($guest, $personas);
            $this->createDirectChat($guest, $personas['lyosha']);
            $this->createGroupChat($guest, $personas);

            return [$guest, $server, $general];
        });

        return [
            'user' => $guest,
            'token' => $this->sessions->issueToken($guest, $request),
            'server_id' => (int) $server->id,
            'channel_id' => (int) $general->id,
        ];
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
        return "demo-{$key}@sonetcord.invalid";
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
            'email' => $login.'@demo.sonetcord.invalid',
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
            'name' => 'Вечерний созвон',
            'description' => 'Демо-сервер: всё настоящее, пробуйте что угодно.',
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
            $member->roles()->attach($user->is($friends['mila']) ? [$everyone->id, $moderators->id] : [$everyone->id]);
        }

        $textCategory = $this->createServerChannel($server, 'Текстовые каналы', ServerChannelKind::Category, 0);
        $general = $this->createServerChannel($server, 'общий', ServerChannelKind::Text, 1, $textCategory, 'Болтаем обо всём');
        $screenshotsChannel = $this->createServerChannel($server, 'скриншоты', ServerChannelKind::Text, 2, $textCategory, 'Скрины, фотки, видео');
        $voiceCategory = $this->createServerChannel($server, 'Голосовые каналы', ServerChannelKind::Category, 3);
        $this->createServerChannel($server, 'Созвон', ServerChannelKind::Voice, 4, $voiceCategory);
        $this->createServerChannel($server, 'Катка', ServerChannelKind::Voice, 5, $voiceCategory);

        $this->postMessage($friends['mila'], $general, 'Всем привет! Это наш сервер для вечерних созвонов 👋', 185);
        $vpnMessage = $this->postMessage($friends['lyosha'], $general, 'О, работает без VPN 🔥', 183);
        $this->postMessage($friends['katya'], $general, 'Дискорд опять не грузит, а тут всё летает', 181);
        $gameInviteMessage = $this->postMessage($friends['anya'], $general, 'Кто сегодня в CS? Я в «Катке» после девяти', 120);
        $this->postMessage($friends['lyosha'], $general, 'Я в деле. Экран тут показывается в 1080p и 60 fps, так что мой позор увидите в полном качестве', 118, $gameInviteMessage);
        $this->postMessage($friends['mila'], $general, 'Фотки с дачи закинула в #скриншоты 🌲', 64);
        $welcomeMessage = $this->postMessage($friends['katya'], $general, 'Привет, новенький! 👋 Тут всё настоящее: пиши, отвечай, ставь реакции, заходи в голосовой, создавай каналы и роли. Через сутки демо сотрётся само.', 3);

        $this->postMessage($friends['mila'], $screenshotsChannel, 'Сюда кидаем скрины и видео. Большие файлы грузятся с докачкой: оборвался интернет — продолжится с того же места.', 63);

        $this->addReactions($vpnMessage, [$friends['katya'], $friends['anya']], '🔥');
        $this->addReactions($gameInviteMessage, [$friends['lyosha'], $friends['mila']], '🎮');
        $this->addReactions($welcomeMessage, [$friends['mila'], $friends['lyosha'], $friends['anya']], '👋');

        return [$server, $general];
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

        $this->postMessage($demoFriend, $channel, 'Привет! Я Лёша, один из демо-друзей 🙂', 30);
        $this->postMessage($demoFriend, $channel, 'Звонки тут как в Discord, но я демо-друг и трубку не возьму 😅 Позвони настоящим друзьям, когда зарегистрируешься. А пока загляни на сервер «Вечерний созвон» слева.', 29);
    }

    /** @param  array<string, User>  $friends */
    private function createGroupChat(User $guest, array $friends): void
    {
        $channel = Channel::create(['name' => 'Дача в субботу 🌲', 'status' => ChannelType::Group]);

        foreach ([$friends['mila'], $guest, $friends['katya'], $friends['anya']] as $user) {
            ChannelMember::create([
                'users_id' => $user->id,
                'channels_id' => $channel->id,
                'status' => $user->is($friends['mila']) ? MembershipStatus::Admin : MembershipStatus::Member,
            ]);
        }

        $creationMessage = new Message([
            'user_id' => $friends['mila']->id,
            'channels_id' => $channel->id,
            'type' => 'system',
            'message' => 'Мила создал(а) беседу',
            'meta' => ['event' => 'channel_created', 'actor_name' => 'Мила'],
        ]);
        $this->backdateMessage($creationMessage, 300)->save();

        $this->postMessage($friends['mila'], $channel, 'Едем в субботу к 12?', 299);
        $this->postMessage($friends['anya'], $channel, 'Я за рулём, возьму троих', 290);
        $this->postMessage($friends['katya'], $channel, 'Беру гитару 🎸', 285);
        $this->postMessage($friends['mila'], $channel, 'Тогда в 12 у метро', 280);
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
