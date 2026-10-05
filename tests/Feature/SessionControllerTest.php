<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_sessions_for_user(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current');
        $user->createToken('device-b');

        $this->withToken($current->plainTextToken)
            ->getJson('/api/auth/sessions')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'sessions')
            ->assertJsonPath('current_session_id', $current->accessToken->getKey());
    }

    public function test_lists_sessions_with_country_and_device(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current');
        $current->accessToken->forceFill([
            'ip_address' => '8.8.8.8',
            'country' => 'Россия (RU)',
            'city' => 'Москва',
            'user_agent' => 'SonetCord/1.0.60 (Windows) Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/138.0.0.0 Electron/37.0.0 Safari/537.36',
        ])->save();

        $this->withToken($current->plainTextToken)
            ->getJson('/api/auth/sessions')
            ->assertOk()
            ->assertJsonFragment([
                'country' => 'Россия (RU)',
                'device' => 'SonetCord 1.0.60 · Windows',
                'os' => 'Windows',
                'device_type' => 'desktop',
            ]);
    }

    public function test_can_revoke_another_session(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current');
        $other = $user->createToken('other-device')->accessToken;

        $this->withToken($current->plainTextToken)
            ->deleteJson("/api/auth/sessions/{$other->getKey()}")
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->getKey()]);
    }

    public function test_cannot_revoke_current_session(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current');

        $this->withToken($current->plainTextToken)
            ->deleteJson("/api/auth/sessions/{$current->accessToken->getKey()}")
            ->assertStatus(422);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->getKey()]);
    }

    public function test_revoke_rejects_invalid_token_id(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current');

        $this->withToken($current->plainTextToken)
            ->deleteJson('/api/auth/sessions/0')
            ->assertStatus(422);
    }

    public function test_revoke_returns_404_for_unknown_token(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current');

        $this->withToken($current->plainTextToken)
            ->deleteJson('/api/auth/sessions/999999')
            ->assertNotFound();
    }

    public function test_cannot_revoke_another_users_token(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current');

        $victim = User::factory()->create();
        $victimToken = $victim->createToken('victim')->accessToken;

        // Чужой токен не виден через $user->tokens() — 404, и он остаётся жив.
        $this->withToken($current->plainTextToken)
            ->deleteJson("/api/auth/sessions/{$victimToken->getKey()}")
            ->assertNotFound();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $victimToken->getKey()]);
    }

    public function test_logout_all_deletes_every_token(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current');
        $user->createToken('b');

        $this->withToken($current->plainTextToken)
            ->postJson('/api/auth/logout-all')
            ->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }
}
