<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class LinkPreviewService
{
    private const MAX_REDIRECTS = 5;

    public function preview(string $url): array
    {
        $normalized = $this->normalizeUrl($url);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Некорректная ссылка');
        }

        if (! $this->isPublicUrl($normalized)) {
            throw new \InvalidArgumentException('Ссылка недоступна для предпросмотра');
        }

        $invitePreview = $this->invitePreview($normalized);
        if ($invitePreview !== null) {
            return $invitePreview;
        }

        $cacheKey = 'link_preview:'.sha1($normalized);

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

    private function isPublicUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host);
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records) || $records === []) {
            return false;
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function fetchPreview(string $url): array
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (! $this->isPublicUrl($current)) {
                throw new \InvalidArgumentException('Ссылка недоступна для предпросмотра');
            }

            try {
                $response = Http::timeout(6)
                    ->withOptions(['allow_redirects' => false])
                    ->withHeaders([
                        'User-Agent' => 'SonetCord-LinkPreview/1.0',
                        'Accept' => 'text/html,application/xhtml+xml',
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

            $html = substr((string) $response->body(), 0, 500_000);

            return [
                'url' => $url,
                'title' => $this->metaTagContent($html, ['og:title', 'twitter:title']) ?? $this->titleTagText($html),
                'description' => $this->metaTagContent($html, ['og:description', 'twitter:description', 'description']),
                'image' => $this->resolveAbsoluteUrl($url, $this->metaTagContent($html, ['og:image', 'twitter:image'])),
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
