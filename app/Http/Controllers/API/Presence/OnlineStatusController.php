<?php

namespace App\Http\Controllers\API\Presence;

use App\Http\Controllers\Controller;
use App\Services\Account\DemoGuestService;
use App\Services\Account\PushNotificationService;
use App\Services\Account\SessionDeviceParser;
use App\Services\Admin\AdminStatsService;
use App\Services\Presence\ActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Клиент периодически сообщает, что пользователь в сети. */
class OnlineStatusController extends Controller
{
    public function __construct(
        private readonly ActivityService $activity,
        private readonly AdminStatsService $stats,
        private readonly SessionDeviceParser $deviceParser,
        private readonly DemoGuestService $demo,
        private readonly PushNotificationService $push,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->touchLastOnline();

        // Демо-гость не настоящий пользователь: в активность и пики онлайна его не пишем.
        if ($user->isDemoGuest()) {
            $this->demo->keepPersonasOnline();

            return $this->successResponse('Время онлайна обновлено');
        }

        $this->activity->markActiveDay($user);
        $this->stats->recordOnlinePresencePeriodically();

        $platform = $this->deviceParser->platformKind($request->userAgent());
        if ($user->last_platform !== $platform) {
            $user->forceFill(['last_platform' => $platform])->save();
        }
        if ($platform === 'desktop') {
            $this->push->markDesktopAppActive((int) $user->id);
        }

        return $this->successResponse('Время онлайна обновлено');
    }
}
