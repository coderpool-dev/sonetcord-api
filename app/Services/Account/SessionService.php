<?php

namespace App\Services\Account;

use App\Data\SessionContext;
use App\Exceptions\ApiException;
use App\Models\Account\PersonalAccessToken;
use App\Models\User;
use App\Services\Presence\GeoIpService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/** Сессии пользователя — его токены Sanctum, по одному на устройство. */
class SessionService
{
    /** Внешний гео-сервис медленный, поэтому за один запрос определяем не больше трёх IP. */
    private const GEO_LOOKUPS_PER_REQUEST = 3;

    public function __construct(
        private readonly GeoIpService $geoIp,
        private readonly SessionDeviceParser $deviceParser,
    ) {}

    /**
     * Выдаёт токен при входе. В отличие от createToken из Sanctum, сразу записывает устройство,
     * IP и город, чтобы сессия появилась в списке «Устройства» с понятным названием.
     *
     * @return string токен в формате Sanctum: «id|секрет»
     */
    public function issueToken(User $user, SessionContext $context): string
    {
        $userAgent = $context->userAgent;
        $location = $this->geoIp->lookupIp($context->ip);
        $secret = Str::random(40);

        $token = $user->tokens()->create([
            'name' => $this->deviceParser->describe($userAgent)['device'],
            'token' => hash('sha256', $secret),
            'abilities' => ['*'],
            'ip_address' => $context->ip,
            'country' => $location['country'],
            'city' => $location['city'],
            'user_agent' => $userAgent,
        ]);

        return $token->getKey().'|'.$secret;
    }

    public function currentToken(User $user): ?PersonalAccessToken
    {
        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token : null;
    }

    /** @return Collection<int, PersonalAccessToken> */
    public function tokensFor(User $user): Collection
    {
        $tokens = PersonalAccessToken::query()
            ->whereMorphedTo('tokenable', $user)
            ->get(['id', 'name', 'ip_address', 'country', 'city', 'user_agent', 'last_used_at', 'created_at', 'expires_at']);

        $this->backfillLocations($tokens);

        return $tokens;
    }

    /** Обновляет IP, страну и браузер у сессии, с которой пришёл запрос. */
    public function refreshLocation(PersonalAccessToken $token, SessionContext $context): void
    {
        $ip = $context->ip;

        if ($this->geoIp->isLocalIp($ip)) {
            return;
        }

        $needsLookup = $this->isUnknownCountry($token->country)
            || blank($token->city)
            || $token->ip_address !== $ip;

        $location = $needsLookup
            ? $this->geoIp->lookupIp($context->ip)
            : ['country' => $token->country, 'city' => $token->city];

        // save() сам ничего не пишет, если значения не изменились.
        $token->forceFill([
            'ip_address' => $ip,
            'country' => $location['country'],
            'city' => $location['city'] ?? $token->city,
            'user_agent' => $context->userAgent ?: $token->user_agent,
        ])->save();
    }

    public function revoke(User $user, int $tokenId): void
    {
        if ($tokenId <= 0) {
            throw new ApiException('Неверный идентификатор сессии', 422);
        }

        $token = $user->tokens()->whereKey($tokenId)->first();

        if (! $token) {
            throw new ApiException('Сессия не найдена', 404);
        }

        if ((int) $token->getKey() === (int) $this->currentToken($user)?->getKey()) {
            throw new ApiException('Нельзя завершить текущую сессию. Для этого используйте выход', 422);
        }

        $token->delete();
    }

    /** @return int сколько сессий завершено */
    public function revokeAll(User $user): int
    {
        return $user->tokens()->delete();
    }

    /**
     * Дозаполняет страну и город у сессий, для которых гео ещё не определено.
     *
     * @param  Collection<int, PersonalAccessToken>  $tokens
     */
    private function backfillLocations(Collection $tokens): void
    {
        $resolved = [];

        foreach ($tokens as $token) {
            if (! $this->needsBackfill($token)) {
                continue;
            }

            $ip = $token->ip_address;

            if (! array_key_exists($ip, $resolved)) {
                if (count($resolved) >= self::GEO_LOOKUPS_PER_REQUEST) {
                    continue;
                }

                $resolved[$ip] = $this->geoIp->lookupIp($ip);
            }

            $token->country = $resolved[$ip]['country'];

            if (blank($token->city) && filled($resolved[$ip]['city'])) {
                $token->city = $resolved[$ip]['city'];
            }

            $token->save();
        }
    }

    private function needsBackfill(PersonalAccessToken $token): bool
    {
        if (blank($token->ip_address)) {
            return false;
        }

        if (! $this->isUnknownCountry($token->country) && filled($token->city)) {
            return false;
        }

        // Локальный адрес определить нельзя — не тратим на него запрос.
        return ! ($token->country === 'local' && $this->geoIp->isLocalIp($token->ip_address));
    }

    private function isUnknownCountry(?string $country): bool
    {
        return blank($country) || in_array($country, ['local', 'Unknown'], true);
    }
}
