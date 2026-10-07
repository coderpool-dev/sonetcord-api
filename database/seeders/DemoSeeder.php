<?php

namespace Database\Seeders;

use App\Data\CreateChannelData;
use App\Enums\FriendStatus;
use App\Models\Conversations\Call;
use App\Models\Conversations\Channel;
use App\Models\Conversations\ChannelMember;
use App\Models\Conversations\Message;
use App\Models\Social\Friend;
use App\Models\User;
use App\Services\Conversations\CallService;
use App\Services\Conversations\ChannelService;
use App\Services\Conversations\MessageService;
use Illuminate\Database\Seeder;

/**
 * Демо-данные для ручной проверки: три друга, общая беседа с сообщениями и активный звонок.
 * Запуск: php artisan db:seed --class=DemoSeeder. Пароль у всех демо-пользователей: password.
 */
class DemoSeeder extends Seeder
{
    private const USERS = [
        ['name' => 'Демо Один', 'login' => 'demo1', 'email' => 'demo1@demo.local'],
        ['name' => 'Демо Два', 'login' => 'demo2', 'email' => 'demo2@demo.local'],
        ['name' => 'Демо Три', 'login' => 'demo3', 'email' => 'demo3@demo.local'],
    ];

    public function run(ChannelService $channels, MessageService $messages, CallService $calls): void
    {
        $this->removePreviousDemoData();

        [$first, $second, $third] = array_map(
            fn (array $attributes) => User::factory()->create([
                ...$attributes,
                'date' => now(),
                'last_online' => now(),
            ]),
            self::USERS,
        );

        foreach ([[$first, $second], [$first, $third], [$second, $third]] as [$user, $friend]) {
            Friend::create([
                'users_id' => $user->id,
                'friend_id' => $friend->id,
                'status' => FriendStatus::Accepted,
            ]);
        }

        $creation = $channels->create($first, CreateChannelData::fromArray([
            'name' => 'Демо-беседа',
            'recipients' => [$second->id, $third->id],
        ]));
        $channelId = (int) $creation->channel->id;

        // Вчерашнее сообщение — чтобы в чате был разделитель дат.
        $messages->storeText($second, $channelId, null, 'Привет! Это вчерашнее сообщение', null)
            ->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])
            ->save();
        $messages->storeText($first, $channelId, null, 'И сегодняшнее тоже', null);
        $messages->storeText($second, $channelId, null, 'Это сообщение у Демо Один непрочитанное', null);

        // Звонок: системное сообщение в беседе и пропущенный звонок у остальных.
        $calls->create($second, $channelId);
    }

    private function removePreviousDemoData(): void
    {
        $users = User::query()->whereIn('email', array_column(self::USERS, 'email'))->get();

        if ($users->isEmpty()) {
            return;
        }

        $userIds = $users->pluck('id');
        $channelIds = ChannelMember::query()->whereIn('users_id', $userIds)->pluck('channels_id')->unique();

        Call::query()->whereIn('channel_id', $channelIds)->delete();
        Message::query()->whereIn('channels_id', $channelIds)->delete();
        ChannelMember::query()->whereIn('channels_id', $channelIds)->delete();
        Channel::query()->whereIn('id', $channelIds)->delete();
        Friend::query()->whereIn('users_id', $userIds)->orWhereIn('friend_id', $userIds)->delete();

        $users->each(fn (User $user) => $user->tokens()->delete());
        User::query()->whereKey($userIds)->delete();
    }
}
