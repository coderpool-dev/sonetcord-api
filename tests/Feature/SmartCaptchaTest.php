<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmartCaptchaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.smartcaptcha.server_key', 'server-test-key');
    }

    public function test_demo_requires_captcha_when_enabled(): void
    {
        $this->postJson('/api/demo')->assertUnprocessable()->assertJsonValidationErrors('captcha_token');
    }

    public function test_production_rejects_demo_when_captcha_is_not_configured(): void
    {
        config()->set('services.smartcaptcha.server_key', null);
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->postJson('/api/demo')->assertStatus(503)->assertJsonPath('code', 'CAPTCHA_NOT_CONFIGURED');
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_invalid_captcha_is_rejected(): void
    {
        Http::fake(['smartcaptcha.cloud.yandex.ru/*' => Http::response(['status' => 'failed'], 200)]);

        $this->postJson('/api/demo', ['captcha_token' => 'bad-token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('captcha_token');
    }

    public function test_valid_captcha_allows_demo(): void
    {
        Http::fake(['smartcaptcha.cloud.yandex.ru/*' => Http::response(['status' => 'ok'], 200)]);

        $this->postJson('/api/demo', ['captcha_token' => 'valid-token'])->assertCreated();

        Http::assertSent(fn ($request) => $request['secret'] === 'server-test-key'
            && $request['token'] === 'valid-token');
    }
}
