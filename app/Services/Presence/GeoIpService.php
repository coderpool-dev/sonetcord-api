<?php

namespace App\Services\Presence;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Страна и город по IP. Внешние сервисы бесплатные и с лимитами, поэтому ответы кэшируются. */
class GeoIpService
{
    /** Удачный ответ живёт месяц: город по IP меняется редко. */
    private const LOOKUP_TTL = 60 * 60 * 24 * 30;

    /** Неудачу держим 10 минут: провайдер мог просто упереться в лимит 45 запросов в минуту. */
    private const LOOKUP_FAIL_TTL = 60 * 10;

    public function isLocalIp(?string $ip): bool
    {
        if ($ip === null || in_array($ip, ['', '127.0.0.1', '::1'], true) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /** IP клиента за Cloudflare и nginx: первый публичный адрес из заголовков, иначе адрес соединения. */
    public function resolveClientIp(Request $request): string
    {
        return $request->ip() ?: '127.0.0.1';
    }

    /** @return array{country: string, country_code: string|null, city: string|null} */
    public function locationFromRequest(Request $request): array
    {
        $lookup = $this->lookupIp($this->resolveClientIp($request));

        if (! in_array($lookup['country'], ['Unknown', 'local'], true)) {
            return $lookup;
        }

        // Внешние сервисы не помогли — страну хотя бы знает Cloudflare.
        return $lookup;
    }

    public function countryFromRequest(Request $request): string
    {
        return $this->locationFromRequest($request)['country'];
    }

    /** Есть ли готовый ответ по этому IP — чтобы фоновая команда не ждала внешний сервис зря. */
    public function isLookupCached(?string $ip): bool
    {
        return $this->isLocalIp($ip) || Cache::has($this->cacheKey((string) $ip));
    }

    /** @return array{country: string, country_code: string|null, city: string|null} */
    public function lookupIp(?string $ip): array
    {
        if ($this->isLocalIp($ip)) {
            return ['country' => 'local', 'country_code' => null, 'city' => null];
        }

        $cached = Cache::get($this->cacheKey($ip));

        if (is_array($cached) && array_key_exists('country', $cached)) {
            return $cached;
        }

        $location = $this->fetchFromProviders($ip);

        Cache::put(
            $this->cacheKey($ip),
            $location,
            $location['country'] === 'Unknown' ? self::LOOKUP_FAIL_TTL : self::LOOKUP_TTL,
        );

        return $location;
    }

    /** @return array{country: string, country_code: string|null, city: string|null} */
    private function fetchFromProviders(string $ip): array
    {
        $encoded = urlencode($ip);

        // ip-api.com бесплатно отвечает только по http. Если он недоступен, спрашиваем ipwho.is.
        $providers = [
            [
                'url' => "http://ip-api.com/json/{$encoded}?fields=status,country,countryCode,city&lang=ru",
                'ok' => fn (array $payload) => ($payload['status'] ?? null) === 'success',
                'code' => 'countryCode',
            ],
            [
                'url' => "https://ipwho.is/{$encoded}?fields=success,country,country_code,city",
                'ok' => fn (array $payload) => ($payload['success'] ?? false) === true,
                'code' => 'country_code',
            ],
        ];

        foreach ($providers as $provider) {
            try {
                $payload = Http::timeout(3)->get($provider['url'])->json();
            } catch (ConnectionException $e) {
                Log::warning('GeoIP provider is unavailable', ['error' => $e->getMessage()]);

                continue;
            }

            if (! is_array($payload) || ! $provider['ok']($payload)) {
                continue;
            }

            $name = trim((string) ($payload['country'] ?? ''));
            $code = strtoupper(trim((string) ($payload[$provider['code']] ?? '')));
            $city = trim((string) ($payload['city'] ?? ''));

            if ($name !== '' && preg_match('/^[A-Z]{2}$/', $code)) {
                return ['country' => "{$name} ({$code})", 'country_code' => $code, 'city' => $city !== '' ? $city : null];
            }
        }

        return ['country' => 'Unknown', 'country_code' => null, 'city' => null];
    }

    private function cacheKey(string $ip): string
    {
        return 'geoip:lookup:'.$ip;
    }
}
