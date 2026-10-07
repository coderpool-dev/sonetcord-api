<?php

namespace App\Services\Admin;

use App\Models\Admin\AppStat;
use App\Models\Admin\DailyStat;
use App\Models\Conversations\Call;
use App\Models\Conversations\CallSession;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminStatsService
{
    public const PEAK_KEY = 'online_peak';

    public const PEAK_AT_KEY = 'online_peak_at';

    /** Как часто сигналы онлайна пересчитывают пик: один раз на весь сервис за этот интервал. */
    public const PRESENCE_RECORD_SECONDS = 30;

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Сигнал «я в сети» приходит раз в 25 с от каждого клиента. Пересчитывать весь онлайн на каждый
     * из них — нагрузка, растущая с квадратом числа людей, поэтому пик обновляется раз в PRESENCE_RECORD_SECONDS.
     */
    public function recordOnlinePresencePeriodically(): void
    {
        if ($this->cache->add('online:presence-recorded', true, self::PRESENCE_RECORD_SECONDS)) {
            $this->recordOnlinePresence();
        }
    }

    /** Пересчитывает онлайн и обновляет общий и дневной пик; возвращает текущий онлайн. */
    public function recordOnlinePresence(): int
    {
        $online = User::countOnline();

        $this->updateAllTimePeak($online);
        $this->updateDailyPeak($online);

        return $online;
    }

    public function snapshot(int $days = 14): array
    {
        $online = $this->recordOnlinePresence();
        $todayStart = now()->startOfDay();
        $liveCalls = $this->liveCallCounts();
        $peak = DailyStat::query()->orderByDesc('online_peak')->orderBy('updated_at')->first();

        return [
            'users_total' => User::query()->real()->count(),
            'users_online' => $online,
            // Общий рекорд — максимум всех сохранённых дневных пиков, независимо от периода графика.
            'online_peak' => (int) ($peak->online_peak ?? $online),
            'online_peak_at' => $peak?->updated_at?->toIso8601String(),
            'online_peak_since' => DailyStat::query()->min('day'),
            'calls_active' => $liveCalls['calls'],
            'calls_participants_now' => $liveCalls['participants'],
            'calls_today' => Call::query()->where('created_at', '>=', $todayStart)->count(),
            'signups_today' => User::query()->real()->where('created_at', '>=', $todayStart)->count(),
            'series' => $this->series(max(7, min(30, $days))),
        ];
    }

    private function updateAllTimePeak(int $online): void
    {
        AppStat::query()->firstOrCreate(['key' => self::PEAK_KEY], ['value' => '0']);
        DB::transaction(function () use ($online): void {
            $peak = AppStat::query()->whereKey(self::PEAK_KEY)->lockForUpdate()->firstOrFail();
            if ($online > (int) $peak->value) {
                $peak->update(['value' => (string) $online]);
                AppStat::setValue(self::PEAK_AT_KEY, now()->toIso8601String());
            }
        }, 3);
    }

    private function updateDailyPeak(int $online): void
    {
        // whereDate, а не firstOrCreate(['day' => …]): в SQLite (тесты) дата хранится со временем,
        // и поиск по строке Y-m-d не находил строку — создавался дубль дня.
        $today = DailyStat::query()->whereDate('day', now()->toDateString())->first()
            ?? DailyStat::query()->create(['day' => now()->toDateString(), 'online_peak' => 0, 'signups' => 0, 'calls_started' => 0]);

        DailyStat::query()->whereKey($today->getKey())->where('online_peak', '<', $online)
            ->update(['online_peak' => $online]);
    }

    /**
     * Только настоящие люди, как в списке звонков на вкладке «Активность»: демо-персонажи «стримят»
     * в демо-серверах постоянно, и без фильтра карточка показывала звонки, которых нет в списке.
     *
     * @return array{calls: int, participants: int}
     */
    private function liveCallCounts(): array
    {
        $counts = CallSession::query()
            ->whereIn('call_id', Call::query()->active()->select('call_id'))
            ->whereIn('user_id', User::query()->real()->select('id'))
            ->where('last_seen_at', '>=', now()->subSeconds(CallSession::FRESH_SECONDS))
            ->toBase()
            ->selectRaw('COUNT(DISTINCT call_id) as calls, COUNT(DISTINCT user_id) as participants')
            ->first();

        return [
            'calls' => (int) ($counts->calls ?? 0),
            'participants' => (int) ($counts->participants ?? 0),
        ];
    }

    /** @return list<array{day: string, online_peak: int, signups: int, calls: int}> */
    private function series(int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $to = now()->endOfDay();

        $daily = DailyStat::query()
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn (DailyStat $row) => $row->day->toDateString());

        $signups = $this->countPerDay(User::query()->real()->toBase(), $from);
        $calls = $this->countPerDay(Call::query()->toBase(), $from);

        $points = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $points[] = [
                'day' => $key,
                // Only measured simultaneous presence counts are online peaks.
                'online_peak' => (int) ($daily->get($key)->online_peak ?? 0),
                'online_peak_recorded' => $daily->has($key),
                'signups' => (int) ($signups[$key] ?? 0),
                'calls' => (int) ($calls[$key] ?? 0),
            ];
        }

        return $points;
    }

    /** @return array<string, int> */
    private function countPerDay(Builder $query, Carbon $from): array
    {
        return $query
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as total'))
            ->where('created_at', '>=', $from)
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('total', 'day')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}
