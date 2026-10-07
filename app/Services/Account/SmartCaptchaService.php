<?php

namespace App\Services\Account;

use App\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmartCaptchaService
{
    public function isEnabled(): bool
    {
        return trim((string) config('services.smartcaptcha.server_key')) !== '';
    }

    public function validate(string $token, string $ip): bool
    {
        try {
            $response = Http::asForm()
                ->connectTimeout(2)
                ->timeout(4)
                ->post((string) config('services.smartcaptcha.validate_url'), [
                    'secret' => (string) config('services.smartcaptcha.server_key'),
                    'token' => $token,
                    'ip' => $ip,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('SmartCaptcha is unavailable', ['error' => $e->getMessage()]);

            throw new ApiException('Проверка защиты временно недоступна. Попробуйте ещё раз', 503, [
                'code' => 'CAPTCHA_UNAVAILABLE',
            ]);
        }

        if (! $response->successful()) {
            Log::warning('SmartCaptcha returned an HTTP error', ['status' => $response->status()]);

            throw new ApiException('Проверка защиты временно недоступна. Попробуйте ещё раз', 503, [
                'code' => 'CAPTCHA_UNAVAILABLE',
            ]);
        }

        return $response->json('status') === 'ok';
    }
}
