<?php

namespace App\Models\Servers;

use Illuminate\Database\Eloquent\Model;

/** До какого сообщения пользователь дочитал текстовый канал сервера — см. App\Services\Servers\ServerChannelReadService. */
class ServerChannelRead extends Model
{
    protected $table = 'server_channel_reads';

    protected $fillable = ['user_id', 'server_channel_id', 'last_read_message_id'];
}
