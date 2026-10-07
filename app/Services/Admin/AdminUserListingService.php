<?php

namespace App\Services\Admin;

use App\Enums\CallStatus;
use App\Enums\FriendStatus;
use App\Models\Conversations\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Список пользователей для админки: активность, звонки, друзья и город. */
class AdminUserListingService
{
    public function listUsers(?string $search = null): array
    {
        $userRows = $this->usersWithActivity($search)->get();
        $locations = $this->dominantTokenLocations($userRows->modelKeys());
        $threshold = now()->subSeconds(User::ONLINE_THRESHOLD_SECONDS);
        $inactiveCutoff = now()->subDays(30);

        $users = $userRows
            ->map(fn (User $user) => $this->serializeUser($user, $threshold, $locations[(int) $user->id] ?? null))
            ->all();

        $summary = collect($users);

        return [
            'users' => $users,
            'summary' => [
                'total' => $summary->count(),
                'online' => $summary->where('online', true)->count(),
                'verified' => $summary->where('email_verified', true)->count(),
                'admins' => $summary->where('is_admin', true)->count(),
                'inactive_30d' => $summary
                    ->filter(fn (array $user) => $user['last_online'] === null
                        || Carbon::parse($user['last_online'])->lt($inactiveCutoff))
                    ->count(),
            ],
        ];
    }

    /** @return Builder<User> пользователи со счётчиками сообщений, звонков и друзей, самые активные сверху */
    private function usersWithActivity(?string $search): Builder
    {
        return User::query()
            ->real()
            ->select([
                'users.id',
                'users.login',
                'users.name',
                'users.avatar',
                'users.banner',
                'users.banner_color',
                'users.updated_at',
                'users.presence',
                'users.game_status_text',
                'users.game_status_synced_at',
                'users.last_online',
                'users.created_at',
                'users.email_verified_at',
            ])
            ->selectRaw('COALESCE(msg.cnt, 0) as messages_count')
            ->selectRaw('COALESCE(cal.cnt, 0) as calls_count')
            ->selectRaw('COALESCE(cal.seconds, 0) as call_seconds')
            ->selectRaw('COALESCE(fr.cnt, 0) as friends_count')
            ->selectRaw('((COALESCE(cal.seconds, 0) DIV 60) + COALESCE(msg.cnt, 0) + COALESCE(fr.cnt, 0) * 2) as activity_score')
            ->leftJoinSub($this->messageCounts(), 'msg', 'msg.user_id', '=', 'users.id')
            ->leftJoinSub($this->callStats(), 'cal', 'cal.user_id', '=', 'users.id')
            ->leftJoinSub($this->friendCounts(), 'fr', 'fr.user_id', '=', 'users.id')
            ->with(['privileges:id,name'])
            ->when($search !== null && trim($search) !== '', function (Builder $query) use ($search) {
                $term = '%'.mb_strtolower(trim((string) $search)).'%';

                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(login) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
            })
            ->orderByDesc('call_seconds')
            ->orderByDesc('messages_count')
            ->orderByDesc('friends_count')
            ->orderByDesc('calls_count')
            ->orderByDesc('last_online')
            ->orderByDesc('created_at');
    }

    /** @return Builder<Message> */
    private function messageCounts(): Builder
    {
        return Message::query()
            ->selectRaw('user_id, COUNT(*) as cnt')
            ->whereNotNull('user_id')
            ->groupBy('user_id');
    }

    /** Число звонков и секунды разговора: завершённые отвеченные звонки плюс идущие сейчас сессии. */
    private function callStats(): QueryBuilder
    {
        $endedTalk = DB::table('calls as c')
            ->join('channels_members as cm', 'cm.channels_id', '=', 'c.channel_id')
            ->where('c.answered', 1)
            ->where('c.status', CallStatus::Ended->value)
            ->selectRaw('cm.users_id as user_id, c.call_id as call_id, GREATEST(0, TIMESTAMPDIFF(SECOND, c.created_at, c.updated_at)) as seconds');

        $liveTalk = DB::table('call_sessions')
            ->selectRaw('user_id, call_id, GREATEST(0, TIMESTAMPDIFF(SECOND, created_at, COALESCE(last_seen_at, NOW()))) as seconds');

        return DB::query()
            ->fromSub($endedTalk->unionAll($liveTalk), 'talk')
            ->selectRaw('user_id, COUNT(DISTINCT call_id) as cnt, COALESCE(SUM(seconds), 0) as seconds')
            ->groupBy('user_id');
    }

    /**
     * Дружба — одна строка на пару: users_id позвал, friend_id принял. Считаем обе стороны,
     * иначе у тех, кто в основном принимал заявки, в админке стоял ноль друзей.
     */
    private function friendCounts(): QueryBuilder
    {
        $pairs = DB::table('friend')
            ->select(['users_id as user_id'])
            ->where('status', FriendStatus::Accepted)
            ->unionAll(DB::table('friend')->select(['friend_id as user_id'])->where('status', FriendStatus::Accepted));

        return DB::query()
            ->fromSub($pairs, 'fp')
            ->selectRaw('user_id, COUNT(*) as cnt')
            ->groupBy('user_id');
    }

    /** @param  array{country: string|null, city: string|null}|null  $tokenLocation */
    private function serializeUser(User $user, Carbon $onlineThreshold, ?array $tokenLocation): array
    {
        $lastOnline = $user->last_online ? Carbon::parse($user->last_online) : null;
        $location = $this->parseLocation($tokenLocation['country'] ?? null, $tokenLocation['city'] ?? null);

        return [
            'id' => (int) $user->id,
            'login' => (string) $user->login,
            'name' => (string) $user->name,
            'avatar' => User::getAvatarUrl($user->avatar, $user->updated_at?->toISOString()),
            'banner' => User::getBannerUrl($user->banner, $user->updated_at?->toISOString()),
            'banner_color' => $user->banner_color,
            'presence' => $user->presence ?? 'online',
            // Аксессор сам скроет статус, если клиент не подтверждал его дольше TTL.
            'game_status_text' => $user->game_status_text,
            'online' => $lastOnline !== null && $lastOnline->gte($onlineThreshold),
            'is_admin' => $user->isAdmin(),
            'email_verified' => $user->email_verified_at !== null,
            'created_at' => $user->created_at?->toIso8601String(),
            'last_online' => $lastOnline?->toIso8601String(),
            'inactive_days' => $lastOnline ? max(0, (int) $lastOnline->diffInDays(now())) : null,
            // Счётчики пришли из selectRaw в usersWithActivity, у модели таких полей нет.
            'messages_count' => (int) $user->getAttribute('messages_count'),
            'calls_count' => (int) $user->getAttribute('calls_count'),
            'call_seconds' => (int) $user->getAttribute('call_seconds'),
            'friends_count' => (int) $user->getAttribute('friends_count'),
            'activity_score' => (int) $user->getAttribute('activity_score'),
            'city' => $location['city'],
            'country' => $location['country'],
            'country_code' => $location['country_code'],
        ];
    }

    /**
     * Город, с которого человек заходит обычно, а не в последний раз: разовый вход
     * из поездки или с мобильного не должен подменять постоянное место.
     * Берём самый частый город по сессиям, при равенстве — более свежий.
     *
     * @param  list<int|string>  $userIds
     * @return array<int, array{country: string|null, city: string|null}>
     */
    private function dominantTokenLocations(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $tokensByUser = DB::table('personal_access_tokens')
            ->select(['tokenable_id', 'country', 'city'])
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('tokenable_id');

        $locations = [];

        foreach ($tokensByUser as $userId => $tokens) {
            $cities = [];

            // Строки отсортированы от свежих к старым: первая встреченная — самый свежий вход из этого города.
            foreach ($tokens as $token) {
                if ($token->city) {
                    $cities[$token->city] ??= ['count' => 0, 'token' => $token];
                    $cities[$token->city]['count']++;
                }
            }

            // Сортировка в PHP 8 стабильна: при равном числе входов впереди останется более свежий город.
            uasort($cities, static fn (array $firstCity, array $secondCity) => $secondCity['count'] <=> $firstCity['count']);

            // Города нет ни в одной сессии — берём свежий токен ради страны.
            $best = $cities === [] ? $tokens->first() : reset($cities)['token'];

            $locations[(int) $userId] = ['country' => $best->country, 'city' => $best->city];
        }

        return $locations;
    }

    /**
     * Страна в токене хранится строкой вида «Россия (RU)».
     *
     * @return array{city: string|null, country: string|null, country_code: string|null}
     */
    private function parseLocation(?string $country, ?string $city): array
    {
        $city = $city ?: null;

        if (! $country || in_array($country, ['Unknown', 'local'], true)) {
            return ['city' => $city, 'country' => null, 'country_code' => null];
        }

        if (preg_match('/^(.+?)\s*\(([A-Z]{2})\)$/', $country, $matches)) {
            return ['city' => $city, 'country' => trim($matches[1]), 'country_code' => $matches[2]];
        }

        if (preg_match('/^[A-Z]{2}$/', $country)) {
            return ['city' => $city, 'country' => null, 'country_code' => $country];
        }

        return ['city' => $city, 'country' => $country, 'country_code' => null];
    }
}
