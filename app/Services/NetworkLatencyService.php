<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class NetworkLatencyService
{
    public function record(int $userId, array $sample, array $location): void
    {
        $city = mb_substr(trim($location['city'] ?? '') ?: 'Не определён', 0, 160);
        $country = $location['country_code'] ?? null;
        $country = is_string($country) && preg_match('/^[A-Z]{2}$/', $country) ? $country : null;
        $keys = ['user_id' => $userId, 'hour' => now()->startOfHour(), 'metric' => $sample['metric'],
            'city_key' => hash('sha256', ($country ?? '').':'.mb_strtolower($city))];
        $rtt = $sample['ok'] ? (int) round($sample['rtt_ms']) : null;
        DB::transaction(function () use ($keys, $city, $country, $sample, $rtt) {
            DB::table('network_latency_hours')->insertOrIgnore([...$keys, 'city' => $city,
                'country_code' => $country, 'last_ok' => $sample['ok'], 'last_at' => now()]);
            DB::table('network_latency_hours')->where($keys)->update([
                'rtt_sum' => DB::raw('rtt_sum + '.($rtt ?? 0)),
                'successes' => DB::raw('successes + '.($sample['ok'] ? 1 : 0)),
                'failures' => DB::raw('failures + '.($sample['ok'] ? 0 : 1)),
                'last_rtt' => $rtt, 'last_ok' => $sample['ok'], 'last_at' => now(),
            ]);
        });
        if (Cache::add('latency:prune', true, 3600)) {
            DB::table('network_latency_hours')->where('hour', '<', now()->subDays(30)->startOfHour())->delete();
        }
    }

    public function snapshot(string $metric, ?int $userId = null): array
    {
        $from = now()->startOfHour()->subHours(23);
        $base = DB::table('network_latency_hours')->where('hour', '>=', $from)->where('metric', $metric)
            ->whereIn('user_id', User::query()->real()->select('id'));
        $perCityUser = (clone $base)->selectRaw('user_id, city_key, MAX(city) as city, MAX(country_code) as country_code, SUM(successes) as samples, SUM(failures) as failures, 1.0 * SUM(rtt_sum) / NULLIF(SUM(successes), 0) as user_avg')
            ->groupBy('user_id', 'city_key');
        $cities = DB::query()->fromSub($perCityUser, 'p')->selectRaw('city_key, MAX(city) as city, MAX(country_code) as country_code, COUNT(*) as users, SUM(samples) as samples, SUM(failures) as failures, ROUND(AVG(user_avg), 1) as avg_rtt_ms')
            ->groupBy('city_key')->orderByDesc('avg_rtt_ms')->orderBy('city')->get();
        $perCountryUser = (clone $base)->selectRaw('user_id, country_code, SUM(successes) as samples, SUM(failures) as failures, 1.0 * SUM(rtt_sum) / NULLIF(SUM(successes), 0) as user_avg')
            ->groupBy('user_id', 'country_code');
        $countries = DB::query()->fromSub($perCountryUser, 'p')->selectRaw('country_code, COUNT(*) as users, SUM(samples) as samples, SUM(failures) as failures, ROUND(AVG(user_avg), 1) as avg_rtt_ms')
            ->groupBy('country_code')->orderByDesc('avg_rtt_ms')->orderBy('country_code')->get();
        $perUser = (clone $base)->selectRaw('user_id, SUM(successes) as samples, SUM(failures) as failures, ROUND(1.0 * SUM(rtt_sum) / NULLIF(SUM(successes), 0), 1) as avg_rtt_ms, MAX(last_at) as last_at')->groupBy('user_id');
        if ($userId) {
            $perUser->where('user_id', $userId);
        }
        $count = DB::query()->fromSub(clone $perUser, 'p')->count();
        $users = DB::query()->fromSub($perUser, 'p')->join('users', 'users.id', '=', 'p.user_id')
            ->select('p.*', 'users.login')->orderByDesc('p.last_at')->limit(500)->get();
        // One query for latest samples, including the city at that moment; no N+1 queries.
        $latest = (clone $base)->whereIn('user_id', $users->pluck('user_id'))->orderByDesc('last_at')->orderByDesc('id')->get()->unique('user_id')->keyBy('user_id');
        foreach ($users as $user) {
            $row = $latest->get($user->user_id);
            $user->last_rtt_ms = $row?->last_rtt;
            $user->last_ok = (bool) $row?->last_ok;
            $user->city = $row?->city;
            $user->country_code = $row?->country_code;
            $user->fresh = $row && now()->diffInSeconds($row->last_at, true) <= 180;
        }

        return ['metric' => $metric, 'from' => $from->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            'retention_days' => 30, 'countries' => $countries, 'cities' => $cities, 'users' => $users, 'total_users' => $count, 'truncated' => $count > 500];
    }
}
