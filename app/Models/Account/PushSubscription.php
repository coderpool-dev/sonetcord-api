<?php

namespace App\Models\Account;

use Illuminate\Database\Eloquent\Model;

/** Web Push-подписка браузера — см. App\Services\Account\PushNotificationService. */
class PushSubscription extends Model
{
    protected $table = 'push_subscriptions';

    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'user_agent'];

    protected $hidden = ['p256dh', 'auth'];
}
