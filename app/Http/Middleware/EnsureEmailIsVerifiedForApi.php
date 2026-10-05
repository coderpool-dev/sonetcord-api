<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Без подтверждённой почты приложением пользоваться нельзя. Роуты, которые нужны
 * до подтверждения (профиль, сессии), в routes/api.php вынесены из этой группы.
 *
 * Встроенный middleware verified не подходит: фронт ждёт в ответе code EMAIL_NOT_VERIFIED.
 */
class EnsureEmailIsVerifiedForApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->email_verified_at === null) {
            return response()->json([
                'status' => 'error',
                'code' => 'EMAIL_NOT_VERIFIED',
                'message' => 'Подтвердите почту, чтобы пользоваться SonetCord',
            ], 403);
        }

        return $next($request);
    }
}
