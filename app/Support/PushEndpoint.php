<?php

namespace App\Support;

class PushEndpoint
{
    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! filter_var($url, FILTER_VALIDATE_URL)
            || ($parts['scheme'] ?? '') !== 'https' || ($parts['port'] ?? 443) !== 443
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');
        foreach (config('services.webpush.allowed_hosts', []) as $allowed) {
            if ($host === $allowed || (str_starts_with($allowed, '*.')
                && str_ends_with($host, substr($allowed, 1)) && $host !== substr($allowed, 2))) {
                return true;
            }
        }

        return false;
    }
}
