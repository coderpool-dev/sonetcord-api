<?php

namespace Tests\Unit;

use App\Services\Http\PublicHttpDestination;
use App\Services\Integrations\LinkPreviewService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkPreviewEncodingTest extends TestCase
{
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

    public function test_converts_windows_1251_from_http_header(): void
    {
        $this->assertPreviewEncoding('text/html; charset=cp1251', '', 'Windows-1251');
    }

    public function test_converts_windows_1251_from_html_meta(): void
    {
        $this->assertPreviewEncoding('text/html', '<meta http-equiv="Content-Type" content="text/html; charset=windows-1251">', 'Windows-1251');
    }

    public function test_preserves_utf8(): void
    {
        $this->assertPreviewEncoding('text/html; charset=UTF-8', '', 'UTF-8');
    }

    public function test_malformed_utf8_and_unknown_charset_still_produce_json(): void
    {
        config(['cache.default' => 'array']);
        Http::fake(['https://example.com/*' => Http::response('<title>Broken '.chr(255).'</title>', 200, ['Content-Type' => 'text/html; charset=unknown-encoding'])]);
        $preview = app(LinkPreviewService::class)->preview('https://example.com/broken-encoding');
        $this->assertTrue(mb_check_encoding($preview['title'], 'UTF-8'));
        $this->assertIsString(json_encode($preview, JSON_THROW_ON_ERROR));
    }

    private function assertPreviewEncoding(string $contentType, string $meta, string $encoding): void
    {
        config(['cache.default' => 'array']);
        $url = 'https://example.com/encoding-'.sha1($contentType.$meta);
        Cache::put('link_preview:'.sha1($url), ['title' => chr(255)], 60);
        $html = $meta.'<title>Привет мир</title><meta property="og:description" content="Описание страницы">';
        Http::fake([$url => Http::response(mb_convert_encoding($html, $encoding, 'UTF-8'), 200, ['Content-Type' => $contentType])]);
        $preview = app(LinkPreviewService::class)->preview($url);
        $this->assertSame('Привет мир', $preview['title']);
        $this->assertSame('Описание страницы', $preview['description']);
        $this->assertIsString(json_encode($preview, JSON_THROW_ON_ERROR));
        Http::assertSentCount(1);
    }
}
