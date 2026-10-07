<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailVerificationLinkNotification;
use App\Notifications\PasswordResetLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload = [
        'name' => 'New User',
        'email' => 'new@example.test',
        'login' => 'newuser',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ];

    public function test_register_creates_user_and_returns_token(): void
    {
        Notification::fake();

        $this->postJson('/api/register', $this->validPayload)
            ->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['user'])
            ->assertJsonMissing(['token']);

        $this->assertDatabaseHas('users', ['email' => 'new@example.test', 'login' => 'newuser']);

        // Пароль захеширован, а не хранится в открытом виде.
        $user = User::firstWhere('email', 'new@example.test');
        $this->assertNotSame('password123', $user->password);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, EmailVerificationLinkNotification::class);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'new@example.test', 'login' => 'taken']);

        $this->postJson('/api/register', $this->validPayload)->assertStatus(422);
    }

    public function test_register_allows_same_login_after_unverified_ttl(): void
    {
        Notification::fake();

        $stale = User::factory()->unverified()->create([
            'email' => 'old@example.test',
            'login' => 'newuser',
            'created_at' => now()->subMinutes(61),
            'updated_at' => now()->subMinutes(61),
        ]);

        $this->postJson('/api/register', $this->validPayload)
            ->assertCreated()
            ->assertJsonPath('user.login', 'newuser');

        $this->assertDatabaseMissing('users', ['id' => $stale->id]);
        $this->assertDatabaseHas('users', [
            'email' => 'new@example.test',
            'login' => 'newuser',
        ]);
    }

    public function test_register_blocks_recent_unverified_login(): void
    {
        User::factory()->unverified()->create([
            'email' => 'other@example.test',
            'login' => 'newuser',
        ]);

        $this->postJson('/api/register', $this->validPayload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login']);
    }

    public function test_register_rejects_at_sign_in_login(): void
    {
        $payload = array_merge($this->validPayload, ['login' => '@baduser']);

        $this->postJson('/api/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['login']);
    }

    public function test_register_requires_password_confirmation_match(): void
    {
        $payload = array_merge($this->validPayload, ['password_confirmation' => 'mismatch']);

        $this->postJson('/api/register', $payload)->assertStatus(422);
    }

    public function test_register_accepts_birth_date_before_1970(): void
    {
        Notification::fake();

        $this->postJson('/api/register', array_merge($this->validPayload, ['date' => '1954-04-14']))
            ->assertCreated();

        $this->assertSame('1954-04-14', User::firstWhere('email', 'new@example.test')->date->toDateString());
    }

    public function test_register_rejects_impossible_birth_date(): void
    {
        foreach (['1899-12-31', now()->addDay()->toDateString()] as $date) {
            $this->postJson('/api/register', array_merge($this->validPayload, ['date' => $date]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['date']);
        }
    }

    public function test_register_requires_min_password_length(): void
    {
        $payload = array_merge($this->validPayload, [
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $this->postJson('/api/register', $payload)->assertStatus(422);
    }

    public function test_login_succeeds_with_correct_credentials(): void
    {
        User::factory()->create([
            'email' => 'log@example.test',
            'login' => 'loginuser',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/login', ['email' => 'log@example.test', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'log@example.test',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/login', ['email' => 'log@example.test', 'password' => 'wrongpass'])
            ->assertUnauthorized();
    }

    public function test_login_fails_for_unknown_email(): void
    {
        $this->postJson('/api/login', ['email' => 'ghost@example.test', 'password' => 'password123'])
            ->assertUnauthorized();
    }

    public function test_login_requires_verified_email(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create([
            'email' => 'unverified@example.test',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/login', ['email' => 'unverified@example.test', 'password' => 'password123'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

        Notification::assertSentTo($user, EmailVerificationLinkNotification::class);
    }

    public function test_forgot_password_sends_reset_link_when_user_exists(): void
    {
        Notification::fake();
        config(['app.frontend_url' => 'https://sonetcord.ru']);

        $user = User::factory()->create(['email' => 'forgot@example.test']);

        $this->postJson('/api/forgot-password', ['email' => 'forgot@example.test'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        Notification::assertSentTo($user, PasswordResetLinkNotification::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);
            $actionUrl = $mail->actionUrl;

            return str_starts_with($actionUrl, 'https://sonetcord.ru/reset-password?')
                && str_contains($actionUrl, 'email=forgot%40example.test')
                && str_contains($actionUrl, 'token=');
        });
    }

    public function test_forgot_password_does_not_reveal_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => 'ghost@example.test'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        Notification::assertNothingSent();
    }

    public function test_reset_password_updates_password_with_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.test',
            'password' => Hash::make('oldpassword'),
        ]);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'email' => 'reset@example.test',
            'token' => $token,
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertTrue(Hash::check('newpassword', $user->refresh()->password));
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        User::factory()->create(['email' => 'reset@example.test']);

        $this->postJson('/api/reset-password', [
            'email' => 'reset@example.test',
            'token' => 'bad-token',
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ])->assertStatus(422);
    }
}
