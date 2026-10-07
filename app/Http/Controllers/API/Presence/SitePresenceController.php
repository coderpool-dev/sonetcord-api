<?php

namespace App\Http\Controllers\API\Presence;

use App\Http\Controllers\Controller;
use App\Http\Requests\Presence\PingSitePresenceRequest;
use App\Jobs\ResolveSitePresenceCountry;
use App\Services\Presence\GeoIpService;
use App\Services\Presence\SitePresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/** Пинг с открытой вкладки сайта — для счётчика посетителей в админке. Гости тоже считаются. */
class SitePresenceController extends Controller
{
    public function __construct(
        private readonly SitePresenceService $presence,
        private readonly GeoIpService $geoIp,
    ) {}

    public function ping(PingSitePresenceRequest $request): JsonResponse
    {
        $ip = $this->geoIp->resolveClientIp($request);
        $country = null;

        if ($this->geoIp->isLookupCached($ip)) {
            $country = $this->geoIp->countryFromRequest($request);
        }

        $this->presence->ping(
            // Роут публичный: пользователя определяем по токену, только если он передан.
            $request->user('sanctum'),
            $request->validated('session_key'),
            $request->validated('path') ?? '/',
            $request->validated('referrer'),
            $country,
        );

        if ($country === null && ! $this->geoIp->isLocalIp($ip)) {
            $sessionKey = $request->validated('session_key');
            $queuedKey = 'geoip:presence:queued:'.hash('sha256', $sessionKey);

            // GeoIP is optional. Avoid filling the background queue with repeated
            // heartbeat jobs or exceeding the free provider's request budget.
            if (Cache::add($queuedKey, true, 600)) {
                RateLimiter::attempt('geoip:presence:dispatch', 30, fn () => ResolveSitePresenceCountry::dispatch($sessionKey, $ip), 60);
            }
        }

        return $this->successResponse('Присутствие обновлено');
    }
}
