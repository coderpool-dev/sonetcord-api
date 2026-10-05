<?php

namespace App\Services\Account;

class SessionDeviceParser
{
    /**
     * @return array{device: string, device_type: string, os: ?string, client: ?string}
     */
    public function describe(?string $userAgent): array
    {
        $userAgent = trim((string) $userAgent);
        if ($userAgent === '') {
            return [
                'device' => 'Неизвестное устройство',
                'device_type' => 'unknown',
                'os' => null,
                'client' => null,
            ];
        }

        $os = $this->detectOs($userAgent);
        $client = $this->detectClient($userAgent);
        $deviceType = $this->detectType($userAgent);
        $parts = array_values(array_filter([$client, $os]));

        return [
            'device' => $parts !== [] ? implode(' · ', $parts) : 'Неизвестное устройство',
            'device_type' => $deviceType,
            'os' => $os,
            'client' => $client,
        ];
    }

    /**
     * Грубая классификация клиента для статистики «веб / ПК-приложение / телефон».
     * desktop = наш Electron-клиент (SonetCord/…); mobile = телефон/планшет; web = браузер.
     */
    public function platformKind(?string $userAgent): string
    {
        $userAgent = trim((string) $userAgent);
        if ($userAgent === '') {
            return 'web';
        }

        if (stripos($userAgent, 'SonetCord') !== false || stripos($userAgent, 'Electron/') !== false) {
            return 'desktop';
        }

        if (preg_match('/Mobile|Android|iPhone|iPad|Tablet/i', $userAgent)) {
            return 'mobile';
        }

        return 'web';
    }

    private function detectOs(string $userAgent): ?string
    {
        if (stripos($userAgent, 'iPhone') !== false) {
            return 'iPhone';
        }
        if (stripos($userAgent, 'iPad') !== false) {
            return 'iPad';
        }
        if (stripos($userAgent, 'Android') !== false) {
            return 'Android';
        }
        if (stripos($userAgent, 'Windows') !== false) {
            return 'Windows';
        }
        if (stripos($userAgent, 'Mac OS X') !== false || stripos($userAgent, 'Macintosh') !== false) {
            return 'macOS';
        }
        if (stripos($userAgent, 'CrOS') !== false) {
            return 'ChromeOS';
        }
        if (stripos($userAgent, 'Linux') !== false) {
            return 'Linux';
        }

        return null;
    }

    private function detectClient(string $userAgent): ?string
    {
        if (preg_match('/SonetCord\/([\d.]+)/i', $userAgent, $matches)) {
            return 'SonetCord '.$matches[1];
        }
        if (stripos($userAgent, 'SonetCord') !== false || stripos($userAgent, 'Electron/') !== false) {
            return 'SonetCord';
        }
        if (preg_match('/Edg\/(\d+)/', $userAgent, $matches)) {
            return 'Edge '.$matches[1];
        }
        if (preg_match('/OPR\/(\d+)/', $userAgent, $matches)) {
            return 'Opera '.$matches[1];
        }
        if (preg_match('/Firefox\/(\d+)/', $userAgent, $matches)) {
            return 'Firefox '.$matches[1];
        }
        if (preg_match('/Chrome\/(\d+)/', $userAgent, $matches)) {
            return 'Chrome '.$matches[1];
        }
        if (stripos($userAgent, 'Safari/') !== false && stripos($userAgent, 'Chrome') === false) {
            return 'Safari';
        }

        return null;
    }

    private function detectType(string $userAgent): string
    {
        if (stripos($userAgent, 'iPad') !== false || stripos($userAgent, 'Tablet') !== false) {
            return 'tablet';
        }
        if (preg_match('/Mobile|Android|iPhone/i', $userAgent)) {
            return 'mobile';
        }

        return 'desktop';
    }
}
