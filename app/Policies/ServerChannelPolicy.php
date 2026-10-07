<?php

namespace App\Policies;

use App\Enums\ServerChannelKind;
use App\Enums\ServerPermission;
use App\Models\Servers\ServerChannel;
use App\Models\User;
use App\Services\Servers\ServerChannelAccess;
use Illuminate\Auth\Access\Response;

class ServerChannelPolicy
{
    public function __construct(private readonly ServerChannelAccess $access) {}

    public function view(User $user, ServerChannel $channel): Response
    {
        return $this->allowChannelPermission($user, $channel, ServerPermission::VIEW_CHANNELS);
    }

    public function createMessage(User $user, ServerChannel $channel): Response
    {
        if ($channel->kind !== ServerChannelKind::Text && $channel->kind !== ServerChannelKind::News) {
            return Response::deny('Сообщения можно отправлять только в текстовый или новостной канал');
        }

        return $this->allowChannelPermission($user, $channel, ServerPermission::SEND_MESSAGES);
    }

    public function call(User $user, ServerChannel $channel): Response
    {
        return $this->allowChannelPermission($user, $channel, ServerPermission::CONNECT_VOICE);
    }

    private function allowChannelPermission(User $user, ServerChannel $channel, int $permission): Response
    {
        $denial = $this->access->for($user, $channel)->denial($permission);

        return $denial === null ? Response::allow() : Response::deny($denial);
    }
}
