<?php

namespace Tests\Feature;

use App\Models\Admin\Privilege;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_receives_profile_images_for_users_outside_their_friend_list(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('The admin activity aggregation uses MySQL functions.');
        }

        $admin = User::factory()->create();
        $admin->privileges()->attach(Privilege::firstOrCreate(['name' => 'admin']));
        $withBanner = User::factory()->create([
            'avatar' => 'profile-avatar.jpg', 'banner' => 'profile-banner.jpg', 'banner_color' => '#123456',
            'updated_at' => '2026-10-07 12:00:00',
        ]);
        $withoutBanner = User::factory()->create(['banner' => null, 'banner_color' => '#654321']);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/users')->assertOk();
        $users = collect($response->json('users'))->keyBy('id');
        $profile = $users[$withBanner->id];
        $this->assertSame('/storage/avatars/profile-avatar.jpg', parse_url($profile['avatar'], PHP_URL_PATH));
        $this->assertSame('/storage/banners/profile-banner.jpg', parse_url($profile['banner'], PHP_URL_PATH));
        $this->assertStringContainsString('?v=', $profile['banner']);
        $this->assertSame('#123456', $profile['banner_color']);
        $this->assertNull($users[$withoutBanner->id]['banner']);
        $this->assertSame('#654321', $users[$withoutBanner->id]['banner_color']);
    }
}
