<?php

namespace App\Services\Integrations;

use App\Services\Http\BoundedResponseBody;
use App\Services\Http\PublicHttpDestination;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class LinkPreviewService
{
    private const MAX_REDIRECTS = 5;

    private const MAX_BODY_BYTES = 500_000;

    public function __construct(private readonly PublicHttpDestination $destinations) {}

    public function preview(string $url): array
    {
        $normalized = $this->normalizeUrl($url);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Некорректная ссылка');
        }

        $this->destinations->curlOptions($normalized);

        $invitePreview = $this->invitePreview($normalized);
        if ($invitePreview !== null) {
            return $invitePreview;
        }

        $cacheKey = 'link_preview:v2:'.sha1($normalized);

        return Cache::remember($cacheKey, now()->addHours(6), fn () => $this->fetchPreview($normalized));
    }

    private function normalizeUrl(string $url): ?string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        if (! preg_match('~^https?://~i', $trimmed)) {
            $trimmed = 'https://'.$trimmed;
        }

        $parts = parse_url($trimmed);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $trimmed;
    }

    private function fetchPreview(string $url): array
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $curlOptions = $this->destinations->curlOptions($current);
            $sink = BoundedResponseBody::create(self::MAX_BODY_BYTES);

            try {
                $response = Http::timeout(6)
                    ->setHandler(function ($request, array $options) use ($sink) {
                        // Install the bounded sink at the transport layer, below Laravel middleware.
                        $options['sink'] = $sink;

                        return (new CurlHandler)($request, $options);
                    })
                    ->withOptions([
                        'allow_redirects' => false,
                        'proxy' => '',
                        'curl' => $curlOptions,
                        'decode_content' => false,
                    ])
                    ->withHeaders([
                        'User-Agent' => 'SonetCord-LinkPreview/1.0',
                        'Accept' => 'text/html,application/xhtml+xml',
                        'Accept-Encoding' => 'identity',
                    ])
                    ->get($current);
            } catch (\Throwable) {
                return $this->emptyPreview($url);
            }

            if ($response->redirect()) {
                $location = $response->header('Location');
                if ($location === '') {
                    return $this->emptyPreview($url);
                }

                $current = $this->resolveAbsoluteUrl($current, $location) ?? $location;

                continue;
            }

            if (! $response->successful()) {
                return $this->emptyPreview($url);
            }

            $body = (string) $response->body();
            if (strlen($body) > self::MAX_BODY_BYTES || $response->header('Content-Encoding') !== '') {
                return $this->emptyPreview($url);
            }
            $html = $this->normalizeHtml($body, $response->header('Content-Type'));

            return [
                'url' => $url,
                'title' => $this->metaTagContent($html, ['og:title', 'twitter:title']) ?? $this->titleTagText($html),
                'description' => $this->metaTagContent($html, ['og:description', 'twitter:description', 'description']),
                'image' => $this->resolveAbsoluteUrl($current, $this->metaTagContent($html, ['og:image', 'twitter:image'])),
                'site_name' => $this->metaTagContent($html, ['og:site_name']) ?? parse_url($url, PHP_URL_HOST),
            ];
        }

        return $this->emptyPreview($url);
    }

    private function emptyPreview(string $url): array
    {
        return [
            'url' => $url,
            'title' => null,
            'description' => null,
            'image' => null,
            'site_name' => parse_url($url, PHP_URL_HOST),
        ];
    }

    /** External pages may use legacy encodings or contain broken UTF-8. */
    private function normalizeHtml(string $body, string $contentType): string
    {
        $body = substr($body, 0, self::MAX_BODY_BYTES);
        $charset = null;

        if (preg_match('/charset\s*=\s*["\x27]?([a-zA-Z0-9_-]+)/i', $contentType, $match)) {
            $charset = $match[1];
        } elseif (preg_match('/<meta\b[^>]*charset\s*=\s*["\x27]?([a-zA-Z0-9_-]+)/i', substr($body, 0, 8192), $match)) {
            $charset = $match[1];
        }

        if ($charset !== null) {
            try {
                $body = mb_convert_encoding($body, 'UTF-8', $charset);
            } catch (\ValueError) {
                // An unknown charset must not prevent a JSON response.
            }
        }

        return mb_scrub($body, 'UTF-8');
    }

    /**
     * @param  list<string>  $names
     */
    private function metaTagContent(string $html, array $names): ?string
    {
        foreach ($names as $name) {
            $escaped = preg_quote($name, '/');
            $patterns = [
                '/<meta[^>]+(?:property|name)=["\']'.$escaped.'["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i',
                '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']'.$escaped.'["\'][^>]*>/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $html, $match)) {
                    $value = html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    private function titleTagText(string $html): ?string
    {
        if (! preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match)) {
            return null;
        }

        $value = html_entity_decode(trim(strip_tags($match[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $value !== '' ? $value : null;
    }

    private function resolveAbsoluteUrl(string $baseUrl, ?string $maybeRelative): ?string
    {
        if ($maybeRelative === null || trim($maybeRelative) === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $maybeRelative)) {
            return $maybeRelative;
        }

        $parts = parse_url($baseUrl);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];
        if (! empty($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        if (str_starts_with($maybeRelative, '//')) {
            return $parts['scheme'].':'.$maybeRelative;
        }

        if (str_starts_with($maybeRelative, '/')) {
            return $origin.$maybeRelative;
        }

        $path = $parts['path'] ?? '/';
        $directory = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/') + 1) : '/';

        return $origin.$directory.$maybeRelative;
    }

    /**
     * Свои пригласительные ссылки не скрейпим: иначе в кэше на 6 часов
     * оказывается превью главной (каноникал / размытый скриншот интерфейса).
     */
    private function invitePreview(string $url): ?array
    {
        $siteUrl = rtrim((string) config('app.frontend_url'), '/');
        $siteHost = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($siteHost === '' || ! in_array($host, [$siteHost, 'www.'.$siteHost], true)) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        if (! preg_match('#^/i/([^/]+)/?$#', $path, $match)) {
            return null;
        }

        $login = trim(rawurldecode($match[1]));
        $login = ltrim($login, '@');
        if ($login === '' || strlen($login) < 3) {
            return null;
        }

        $encoded = rawurlencode($login);

        return [
            'url' => $url,
            'title' => $login.' приглашает вас в SonetCord',
            'description' => 'Откройте ссылку, зарегистрируйтесь — и заявка в друзья уйдёт сама.',
            'image' => $siteUrl.'/i/'.$encoded.'/opengraph-image',
            'site_name' => 'SonetCord',
        ];
    }
}
