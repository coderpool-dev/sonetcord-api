<?php

namespace Tests\Feature;

use App\Auth\ThrottledSanctumGuard;
use App\Models\Account\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalAccessTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanctum_uses_throttled_guard(): void
    {
        $this->assertInstanceOf(ThrottledSanctumGuard::class, (fn () => $this->callback)->call(auth()->guard('sanctum')));
    }

    public function test_last_used_at_is_written_at_most_once_per_minute(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $plain = $user->createToken('test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];
        $call = function () use ($plain) {
            $this->withToken($plain)->getJson('/api/auth/profile')->assertOk();
            auth()->forgetGuards();
        };

        $call();
        $first = PersonalAccessToken::find($tokenId)->last_used_at;
        $this->assertNotNull($first);

        $this->travel(30)->seconds();
        $call();
        $this->assertEquals($first, PersonalAccessToken::find($tokenId)->last_used_at);

        $this->travel(ThrottledSanctumGuard::LAST_USED_AT_PRECISION_SECONDS)->seconds();
        $call();
        $this->assertTrue(PersonalAccessToken::find($tokenId)->last_used_at->greaterThan($first));
    }
}
