<?php

namespace App\Auth;

use Laravel\Sanctum\Guard;

/**
 * Guard Sanctum, который отмечает last_used_at токена не чаще раза в минуту.
 * Стандартный пишет его на каждом запросе — лишний UPDATE на каждый пинг и опрос,
 * а для «последней активности» сессии хватает точности в минуту.
 */
class ThrottledSanctumGuard extends Guard
{
    public const LAST_USED_AT_PRECISION_SECONDS = 60;

    protected function updateLastUsedAt($accessToken)
    {
        $lastUsedAt = $accessToken->last_used_at;

        if ($lastUsedAt !== null && $lastUsedAt->diffInSeconds(now(), true) < self::LAST_USED_AT_PRECISION_SECONDS) {
            return;
        }

        parent::updateLastUsedAt($accessToken);
    }
}
