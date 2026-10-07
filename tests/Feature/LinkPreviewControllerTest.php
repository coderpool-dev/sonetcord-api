<?php

namespace Tests\Feature;

use App\Services\Http\PublicHttpDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithCalls;
use Tests\TestCase;

class LinkPreviewControllerTest extends TestCase
{
    use InteractsWithCalls;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(PublicHttpDestination::class, new class extends PublicHttpDestination
        {
            protected function resolveHostname(string $host): array
            {
                return ['93.184.215.14'];
            }
        });
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/link-preview?url=https://example.com')->assertStatus(401);
    }

    public function test_returns_open_graph_preview(): void
    {
        Sanctum::actingAs($this->makeUser(), ['*']);

        Http::fake([
            'https://example.com/*' => Http::response(<<<'HTML'
                <html>
                    <head>
                        <meta property="og:title" content="Example Title" />
                        <meta property="og:description" content="Example description" />
                        <meta property="og:image" content="https://example.com/cover.jpg" />
                        <meta property="og:site_name" content="Example" />
                    </head>
                </html>
            HTML, 200),
        ]);

        $response = $this->getJson('/api/link-preview?url=https://example.com/page')->assertOk();

        $response->assertJsonPath('title', 'Example Title');
        $response->assertJsonPath('description', 'Example description');
        $response->assertJsonPath('image', 'https://example.com/cover.jpg');
        $response->assertJsonPath('site_name', 'Example');
    }

    public function test_builds_invite_preview_without_scraping_the_homepage(): void
    {
        Sanctum::actingAs($this->makeUser(), ['*']);

        Http::fake();

        $response = $this->getJson('/api/link-preview?url=https://sonetcord.ru/i/EgoOne')->assertOk();

        $response->assertJsonPath('title', 'EgoOne приглашает вас в SonetCord');
        $response->assertJsonPath('site_name', 'SonetCord');
        $response->assertJsonPath('image', 'https://sonetcord.ru/i/EgoOne/opengraph-image');
        Http::assertNothingSent();
    }

    public function test_rejects_localhost_urls(): void
    {
        Sanctum::actingAs($this->makeUser(), ['*']);

        $this->getJson('/api/link-preview?url=http://localhost/secret')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ссылка недоступна для предпросмотра');
    }

    public function test_rejects_private_ip_urls(): void
    {
        Sanctum::actingAs($this->makeUser(), ['*']);

        foreach (['http://127.0.0.1/secret', 'http://192.168.0.1/', 'http://10.0.0.5/admin'] as $url) {
            $this->getJson('/api/link-preview?url='.urlencode($url))
                ->assertStatus(422)
                ->assertJsonPath('message', 'Ссылка недоступна для предпросмотра');
        }
    }

    public function test_rejects_redirect_to_private_network(): void
    {
        Sanctum::actingAs($this->makeUser(), ['*']);

        Http::fake([
            'https://example.com/go' => Http::response('', 302, ['Location' => 'http://127.0.0.1/secret']),
        ]);

        $this->getJson('/api/link-preview?url=https://example.com/go')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ссылка недоступна для предпросмотра');
    }

    public function test_checks_and_pins_each_redirect_destination(): void
    {
        Sanctum::actingAs($this->makeUser(), ['*']);
        Http::fake(function ($request, array $options) {
            $host = parse_url($request->url(), PHP_URL_HOST);
            $this->assertSame([$host.':443:93.184.215.14'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);

            return $host === 'example.com'
                ? Http::response('', 302, ['Location' => 'https://other.example/page'])
                : Http::response('<title>Redirected</title><meta property="og:image" content="/cover.png">');
        });
        $this->getJson('/api/link-preview?url=https://example.com/page')->assertOk()
            ->assertJsonPath('title', 'Redirected')->assertJsonPath('image', 'https://other.example/cover.png');
        Http::assertSentCount(2);
    }

    public function test_oversized_body_returns_empty_preview(): void
    {
        Sanctum::actingAs($this->makeUser(), ['*']);
        Http::fake(['*' => Http::response('<title>Too large</title>'.str_repeat('x', 500_000))]);
        $this->getJson('/api/link-preview?url=https://example.com/large')->assertOk()->assertJsonPath('title', null);
    }
}
