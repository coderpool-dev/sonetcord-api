<?php

namespace App\Services\Presence;

use App\Models\Presence\Game;
use App\Models\Presence\GameSession;
use App\Models\Presence\UserActivityDay;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/** Игровая активность: сессии игр, серии дней подряд и сводка для профиля. */
class ActivityService
{
    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Клиент подтверждает, что игра ещё запущена. Без подтверждения статус
     * скрывается по TTL (см. game_status_text в модели User).
     */
    public function confirmGameStatus(User $user): void
    {
        if ($user->getRawOriginal('game_status_text')) {
            $user->forceFill(['game_status_synced_at' => now()])->save();
        }
    }

    /**
     * Отмечает день активности. Вызывается на каждый сигнал онлайна (раз в 25 с), поэтому в базу
     * идём один раз за день на пользователя, дальше ответ знает кэш.
     */
    public function markActiveDay(User $user, ?Carbon $date = null): void
    {
        $day = ($date ?? now())->toDateString();

        if (! $this->cache->add('activity:day:'.$user->id.':'.$day, true, ($date ?? now())->copy()->endOfDay())) {
            return;
        }

        UserActivityDay::firstOrCreate(['user_id' => $user->id, 'activity_date' => $day]);
    }

    public function recordGameStart(User $user, string $gameName): GameSession
    {
        $gameName = $this->normalizeGameName($gameName);

        if ($gameName === '') {
            throw new InvalidArgumentException('Game name is required');
        }

        $game = Game::resolveByName($gameName);

        $openSession = $this->openSessions($user)
            ->first(fn (GameSession $session) => $session->game?->slug === $game->slug);

        if ($openSession) {
            return $openSession;
        }

        $this->markActiveDay($user);

        return GameSession::create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'started_at' => now(),
        ])->load('game');
    }

    /** Без названия игры закрывает все открытые сессии и возвращает самую свежую из них. */
    public function recordGameEnd(User $user, ?string $gameName = null): ?GameSession
    {
        $sessions = $this->openSessions($user);

        if ($gameName !== null && trim($gameName) !== '') {
            $canonical = Game::normalizePublicName($gameName) ?? $this->normalizeGameName($gameName);
            $gameKey = Game::slugFromName($canonical);
            $sessions = $sessions
                ->filter(fn (GameSession $session) => $session->game?->slug === $gameKey)
                ->take(1);
        }

        $sessions->each(fn (GameSession $session) => $session->update(['ended_at' => now()]));

        return $sessions->first();
    }

    /**
     * Закрывает игры, о которых клиент перестал сообщать: десктоп упал или закрылся,
     * не отправив «игра закончилась». Запускается по расписанию каждую минуту.
     */
    public function closeAbandonedSessions(): int
    {
        return GameSession::query()
            ->whereNull('ended_at')
            ->whereIn('user_id', User::query()->gameStatusExpired()->select('id'))
            ->update(['ended_at' => now()]);
    }

    /** @return array{current: int, longest: int} */
    public function calculateGameStreak(User $user, string $gameName): array
    {
        $group = $this->groupPlayDaysByGame($user)[Game::slugFromName($gameName)] ?? null;

        return $group === null
            ? ['current' => 0, 'longest' => 0]
            : $this->streakFromDates($group['dates']);
    }

    /** @return list<array{game: string, current: int, longest: int, is_active: bool}> */
    public function gameStreaks(User $user): array
    {
        $streaks = [];

        foreach ($this->groupPlayDaysByGame($user) as $group) {
            $streaks[] = [
                'game' => $group['display'],
                ...$this->streakFromDates($group['dates']),
                'is_active' => $group['is_active'],
            ];
        }

        // Выше текущая серия, при равенстве — рекорд, затем игра, которая идёт сейчас.
        usort($streaks, fn (array $left, array $right) => [$right['current'], $right['longest'], $right['is_active']]
            <=> [$left['current'], $left['longest'], $left['is_active']]);

        return $streaks;
    }

    /**
     * @param  list<array{game: string, current: int, longest: int, is_active: bool}>  $gameStreaks
     * @param  list<string>  $priorityGames
     * @return array{game: string, current: int, longest: int, is_active: bool}|null
     */
    public function resolveFeaturedStreak(array $gameStreaks, array $priorityGames = []): ?array
    {
        if ($gameStreaks === []) {
            return null;
        }

        $byGame = collect($gameStreaks)->keyBy(fn (array $streak) => Game::slugFromName($streak['game']));

        foreach ($priorityGames as $game) {
            $match = $byGame->get(Game::slugFromName($game));

            if ($match !== null) {
                return $match;
            }
        }

        $best = collect($gameStreaks)
            ->sortByDesc(fn (array $streak) => [$streak['current'], $streak['longest']])
            ->first();

        if ($best['current'] > 0) {
            return $best;
        }

        return collect($gameStreaks)->firstWhere('is_active', true) ?? $best;
    }

    public function profileSummary(User $user, int $historyLimit = 500): array
    {
        $gameStreaks = $this->gameStreaks($user);

        $activeGames = $this->openSessions($user)
            ->map(fn (GameSession $session) => $session->game?->name)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $history = GameSession::query()
            ->with('game')
            ->where('user_id', $user->id)
            ->orderByDesc('started_at')
            ->limit($historyLimit)
            ->get()
            ->map(fn (GameSession $session) => [
                'id' => $session->id,
                'game' => $session->game->name ?? '',
                'started_at' => $session->started_at->toIso8601String(),
                'ended_at' => $session->ended_at?->toIso8601String(),
                'duration_seconds' => $session->durationSeconds(),
                'is_active' => $session->ended_at === null,
            ])
            ->all();

        return [
            'featured_streak' => $this->resolveFeaturedStreak($gameStreaks, $activeGames),
            'game_streaks' => $gameStreaks,
            'history' => $history,
        ];
    }

    /** @return Collection<int, GameSession> */
    private function openSessions(User $user): Collection
    {
        return GameSession::query()
            ->with('game')
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->get();
    }

    private function normalizeGameName(string $gameName): string
    {
        return preg_replace('/\s+/u', ' ', trim($gameName)) ?? '';
    }

    /** @return array<string, array{display: string, dates: array<string, true>, is_active: bool}> */
    private function groupPlayDaysByGame(User $user): array
    {
        $groups = [];

        $sessions = GameSession::query()
            ->with('game')
            ->where('user_id', $user->id)
            ->orderByDesc('started_at')
            ->get(['id', 'game_id', 'started_at', 'ended_at']);

        foreach ($sessions as $session) {
            $name = (string) $session->game?->name;
            $key = $name !== '' ? Game::slugFromName($name) : '';

            if ($key === '') {
                continue;
            }

            $groups[$key] ??= ['display' => $this->normalizeGameName($name), 'dates' => [], 'is_active' => false];
            $groups[$key]['dates'][$session->started_at->toDateString()] = true;
            $groups[$key]['is_active'] = $groups[$key]['is_active'] || $session->ended_at === null;
        }

        return $groups;
    }

    /**
     * @param  array<string, true>  $dates
     * @return array{current: int, longest: int}
     */
    private function streakFromDates(array $dates): array
    {
        if ($dates === []) {
            return ['current' => 0, 'longest' => 0];
        }

        $current = $this->currentStreak($dates);

        return [
            'current' => $current,
            'longest' => max($this->longestStreak(array_keys($dates)), $current),
        ];
    }

    /**
     * Дни подряд, заканчивая сегодняшним. Если сегодня ещё не играл, серия считается от вчера.
     *
     * @param  array<string, true>  $dates
     */
    private function currentStreak(array $dates): int
    {
        $cursor = now()->startOfDay();

        if (! isset($dates[$cursor->toDateString()])) {
            $cursor->subDay();
        }

        $streak = 0;

        while (isset($dates[$cursor->toDateString()])) {
            $streak++;
            $cursor->subDay();
        }

        return $streak;
    }

    /** @param  list<string>  $dates  даты Y-m-d без повторов */
    private function longestStreak(array $dates): int
    {
        sort($dates);

        $longest = 1;
        $current = 1;

        for ($i = 1, $count = count($dates); $i < $count; $i++) {
            $isNextDay = Carbon::parse($dates[$i - 1])->addDay()->isSameDay(Carbon::parse($dates[$i]));
            $current = $isNextDay ? $current + 1 : 1;
            $longest = max($longest, $current);
        }

        return $longest;
    }
}
