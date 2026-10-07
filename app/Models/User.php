<?php

namespace App\Models;

use App\Enums\FriendStatus;
use App\Models\Admin\Privilege;
use App\Models\Conversations\Channel;
use App\Models\Conversations\ChannelMember;
use App\Models\Integrations\YandexMusicConnection;
use App\Models\Integrations\YandexMusicTrackHistory;
use App\Models\Social\Friend;
use App\Notifications\EmailVerificationLinkNotification;
use App\Services\Account\DemoGuestService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property-read YandexMusicConnection|null $yandexMusicConnection
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    /**
     * Сколько секунд статус игры виден без подтверждения от клиента. Десктоп подтверждает раз в 15 с. Если клиент упал, не очистив статус, тот пропадёт сам.
     */
    public const GAME_STATUS_TTL_SECONDS = 40;

    /** Сколько секунд после last_online пользователь считается «в сети». Клиент шлёт сигнал раз в 25 с. */
    public const ONLINE_THRESHOLD_SECONDS = 60;

    /** Чаще этого last_online не перезаписываем: точность «в сети» — ONLINE_THRESHOLD_SECONDS. */
    public const LAST_ONLINE_WRITE_SECONDS = 5;

    /** users.demo_kind: временный аккаунт демо-входа и постоянный демо-друг (см. DemoGuestService). */
    public const DEMO_GUEST = 'guest';

    public const DEMO_PERSONA = 'persona';

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (! array_key_exists('email_verified_at', $user->getAttributes())) {
                $user->email_verified_at = now();
            }
        });
    }

    protected $fillable = [
        'email',
        'name',
        'login',
        'password',
        'avatar',
        'banner',
        'banner_color',
        'presence',
        'status_emoji',
        'status_text',
        'game_status_text',
        'game_status_synced_at',
        'music_status_text',
        'date',
        'email_verified_at',
        'last_online',
        'last_platform',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** Блокировка записана в одну сторону, но запрещает связь в обе. */
    public function isBlockedWith(int $userId): bool
    {
        return Friend::query()->between($this->id, $userId)->where('status', FriendStatus::Blocked)->exists();
    }

    public function isFriend(int $userId): bool
    {
        return Friend::query()->between($this->id, $userId)->where('status', FriendStatus::Accepted)->exists();
    }

    /** @return HasMany<ChannelMember, $this> */
    public function channelMemberships(): HasMany
    {
        return $this->hasMany(ChannelMember::class, 'users_id');
    }

    /** @return Collection<int, int> каналы, в которых пользователь сейчас участник */
    public function activeChannelIds(): Collection
    {
        return $this->channelMemberships()->active()->pluck('channels_id');
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'date' => 'datetime',
            'game_status_synced_at' => 'datetime',
        ];
    }

    /** Статус игры пуст или клиент не подтверждал его дольше TTL — те же правила, что в аксессоре gameStatusText. */
    public function scopeGameStatusExpired(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('game_status_text')
            ->orWhereRaw("TRIM(game_status_text) = ''")
            ->orWhereNull('game_status_synced_at')
            ->orWhere('game_status_synced_at', '<=', now()->subSeconds(self::GAME_STATUS_TTL_SECONDS)));
    }

    /**
     * Статус игры, который клиент не подтверждал дольше GAME_STATUS_TTL_SECONDS, читается как null.
     * В БД значение остаётся, и следующий пинг клиента снова его покажет.
     *
     * @return Attribute<string|null, never>
     */
    protected function gameStatusText(): Attribute
    {
        return Attribute::make(
            get: function (?string $value) {
                if ($value === null || trim($value) === '') {
                    return null;
                }

                $syncedAt = $this->attributes['game_status_synced_at'] ?? null;
                if ($syncedAt === null) {
                    return null;
                }

                $syncedAtCarbon = $syncedAt instanceof \DateTimeInterface
                    ? Carbon::instance($syncedAt)
                    : Carbon::parse($syncedAt);

                return $syncedAtCarbon->gt(now()->subSeconds(self::GAME_STATUS_TTL_SECONDS)) ? $value : null;
            },
        );
    }

    /** Настоящие пользователи — без демо-гостей и демо-друзей. Для статистики и админки. */
    public function scopeReal(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('demo_kind'));
    }

    public function isDemoGuest(): bool
    {
        return $this->demo_kind === self::DEMO_GUEST;
    }

    /** Когда демо-гость будет удалён (demo:prune); у остальных null. */
    public function demoExpiresAt(): ?CarbonInterface
    {
        return $this->isDemoGuest() ? $this->created_at?->copy()->addHours(DemoGuestService::TTL_HOURS) : null;
    }

    /**
     * Отмечает «в сети». Без updated_at: он входит в адрес аватарки (?v=), и сигнал онлайна раз в 25 с
     * менял адрес — браузеры заново скачивали аватарки всех, кто в сети. Пишет не чаще раза
     * в LAST_ONLINE_WRITE_SECONDS: так же отмечают и пинги звонка (раз в 15 с с каждого устройства).
     */
    public function touchLastOnline(): void
    {
        $now = now();
        static::query()->whereKey($this->getKey())
            ->where(fn ($query) => $query->whereNull('last_online')
                ->orWhere('last_online', '<', $now->copy()->subSeconds(self::LAST_ONLINE_WRITE_SECONDS)))
            ->toBase()
            ->update(['last_online' => $now]);
        $this->forceFill(['last_online' => $now])->syncOriginalAttribute('last_online');
    }

    public function isOnline(): bool
    {
        // Колонка NOT NULL, но пустой бывает, когда пользователь загружен без неё в select.
        if (! $this->last_online) {
            return false;
        }

        $seconds = now()->diffInSeconds($this->last_online);

        return abs($seconds) < self::ONLINE_THRESHOLD_SECONDS;
    }

    /** Письмо со ссылкой подтверждения почты. Ссылка подписана и действует час. */
    public function sendEmailVerificationLink(): void
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $this->id,
                'hash' => sha1($this->email),
            ]
        );

        $this->notify(new EmailVerificationLinkNotification($url));
    }

    public static function getAvatarUrl(?string $avatar, ?string $updatedAt = null): string
    {
        if (empty($avatar)) {
            return asset('storage/avatars/default.png');
        }

        $url = asset('storage/avatars/'.ltrim($avatar, '/'));

        if ($updatedAt) {
            $url .= '?v='.strtotime($updatedAt);
        }

        return $url;
    }

    /**
     * URL баннера профиля. В отличие от аватара дефолта-картинки нет:
     * если файл не загружен — возвращаем null, фронт рисует цвет-заглушку.
     */
    public static function getBannerUrl(?string $banner, ?string $updatedAt = null): ?string
    {
        if (empty($banner)) {
            return null;
        }

        $url = asset('storage/banners/'.ltrim($banner, '/'));

        if ($updatedAt) {
            $url .= '?v='.strtotime($updatedAt);
        }

        return $url;
    }

    /** @return BelongsToMany<Channel, $this> */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channels_members', 'users_id', 'channels_id');
    }

    /** @return BelongsToMany<Privilege, $this> */
    public function privileges(): BelongsToMany
    {
        return $this->belongsToMany(Privilege::class, 'privilege_user')->withTimestamps();
    }

    public function isAdmin(): bool
    {
        if ($this->relationLoaded('privileges')) {
            return $this->privileges->contains('name', Privilege::ADMIN);
        }

        return $this->privileges()->where('name', Privilege::ADMIN)->exists();
    }

    public static function countOnline(): int
    {
        return static::query()
            ->real()
            ->where('last_online', '>=', now()->subSeconds(self::ONLINE_THRESHOLD_SECONDS))
            ->count();
    }

    /** @return HasOne<YandexMusicConnection, $this> */
    public function yandexMusicConnection(): HasOne
    {
        return $this->hasOne(YandexMusicConnection::class);
    }

    /** @return HasMany<YandexMusicTrackHistory, $this> */
    public function yandexMusicTrackHistories(): HasMany
    {
        return $this->hasMany(YandexMusicTrackHistory::class);
    }
}
