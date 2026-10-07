<?php

namespace App\Jobs;

use App\Models\Presence\SitePresenceSession;
use App\Services\Presence\GeoIpService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** GeoIP зависит от внешних сервисов, поэтому никогда не выполняется в HTTP-запросе. */
class ResolveSitePresenceCountry implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 15;

    public function __construct(
        private readonly string $sessionKey,
        private readonly string $ip,
    ) {
        $this->onConnection('database')->onQueue('background');
    }

    public function handle(GeoIpService $geoIp): void
    {
        $country = $geoIp->lookupIp($this->ip)['country'];

        if (in_array($country, ['Unknown', 'local'], true)) {
            return;
        }

        SitePresenceSession::query()
            ->where('session_key', $this->sessionKey)
            ->whereNull('country')
            ->update(['country' => $country]);
    }
}
