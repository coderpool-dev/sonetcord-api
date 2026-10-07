<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\Account\SmartCaptchaService;
use App\Services\Presence\GeoIpService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ValidateSmartCaptcha
{
    public function __construct(
        private readonly SmartCaptchaService $captcha,
        private readonly GeoIpService $geoIp,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->captcha->isEnabled()) {
            if (app()->isProduction()) {
                throw new ApiException('Captcha is not configured', 503, ['code' => 'CAPTCHA_NOT_CONFIGURED']);
            }

            return $next($request);
        }

        $token = trim((string) $request->input('captcha_token'));

        if ($token === '' || ! $this->captcha->validate($token, $this->geoIp->resolveClientIp($request))) {
            throw ValidationException::withMessages([
                'captcha_token' => ['Подтвердите, что вы не робот'],
            ]);
        }

        return $next($request);
    }
}
