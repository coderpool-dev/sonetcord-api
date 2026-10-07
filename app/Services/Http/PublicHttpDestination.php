<?php

namespace App\Services\Http;

use InvalidArgumentException;

/** Resolve once and pin the connection while retaining Host, SNI and TLS verification. */
class PublicHttpDestination
{
    public function curlOptions(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Ссылка недоступна для предпросмотра');
        }

        $host = strtolower($parts['host'] ?? '');
        $ipHost = trim($host, '[]');
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true) || $host === 'localhost' || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('Ссылка недоступна для предпросмотра');
        }

        $ips = filter_var($ipHost, FILTER_VALIDATE_IP) ? [$ipHost] : $this->resolveHostname($host);
        if ($ips === []) {
            throw new InvalidArgumentException('Ссылка недоступна для предпросмотра');
        }
        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new InvalidArgumentException('Ссылка недоступна для предпросмотра');
            }
        }

        // Literal addresses need no DNS override; libcurl receives the already validated IP.
        $resolve = filter_var($ipHost, FILTER_VALIDATE_IP) ? [] : [
            $host.':'.$port.':'.(str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0]),
        ];

        return [CURLOPT_RESOLVE => $resolve, CURLOPT_PROXY => ''];
    }

    /** @return list<string> */
    protected function resolveHostname(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        $binary = inet_pton($ip);
        if ($binary === false) {
            return false;
        }
        if (strlen($binary) === 4) {
            $first = ord($binary[0]);
            $second = ord($binary[1]);

            return $first !== 0 && $first < 224 && ! ($first === 100 && $second >= 64 && $second <= 127);
        }

        // Only global unicast IPv6; exclude mapped addresses and transition tunnels.
        return (ord($binary[0]) & 0xE0) === 0x20
            && substr($binary, 0, 2) !== "\x20\x02"
            && substr($binary, 0, 4) !== "\x20\x01\x00\x00";
    }
}
