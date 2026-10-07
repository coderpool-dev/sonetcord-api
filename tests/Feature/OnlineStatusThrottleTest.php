<?php

namespace Tests\Feature;

use App\Models\Admin\DailyStat;
use App\Models\Presence\UserActivityDay;
use App\Models\User;
use App\Services\Admin\AdminStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnlineStatusThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_online_ping_marks_activity_day_once(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/auth/online')->assertOk();
        $this->postJson('/api/auth/online')->assertOk();

        $this->assertSame(1, UserActivityDay::query()->where('user_id', $user->id)->count());
        $this->assertTrue($user->fresh()->isOnline());
    }

    public function test_online_peak_is_recalculated_at_most_once_per_interval(): void
    {
        $first = User::factory()->create();
        Sanctum::actingAs($first, ['*']);
        $this->postJson('/api/auth/online')->assertOk();
        $this->assertSame(1, DailyStat::query()->value('online_peak'));

        // Второй человек в сети, но окно пересчёта ещё не прошло — пик прежний.
        User::factory()->create()->touchLastOnline();
        $this->postJson('/api/auth/online')->assertOk();
        $this->assertSame(1, DailyStat::query()->value('online_peak'));

        $this->travel(AdminStatsService::PRESENCE_RECORD_SECONDS + 1)->seconds();
        User::query()->get()->each->touchLastOnline();
        $this->postJson('/api/auth/online')->assertOk();
        $this->assertSame(2, DailyStat::query()->value('online_peak'));
    }
}
