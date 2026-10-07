<?php

namespace App\Http\Controllers\API\Account;

use App\Data\LoginData;
use App\Data\RegisterData;
use App\Data\ResetPasswordData;
use App\Data\SessionContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Services\Account\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->register(RegisterData::fromArray($request->validated()));

        return $this->successResponse('Пользователь успешно зарегистрирован. Проверьте почту и подтвердите аккаунт.', [
            'user' => new UserResource($user->load('yandexMusicConnection')),
            'verification_email_sent' => true,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            LoginData::fromArray($request->validated()),
            SessionContext::fromRequest($request),
        );

        return $this->successResponse('Вход выполнен успешно', [
            'user' => new UserResource($result->user->load('yandexMusicConnection')),
            'token' => $result->token,
        ]);
    }

    /** Ссылка из письма: после подтверждения ведём на страницу входа фронтенда. */
    public function verifyEmail(int $id, string $hash): RedirectResponse
    {
        $loginUrl = rtrim(config('app.frontend_url'), '/').'/login';

        return redirect($loginUrl.($this->auth->verifyEmail($id, $hash) ? '?verified=1' : '?verified=invalid'));
    }

    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->auth->resendVerificationEmail($user)) {
            return $this->successResponse('Почта уже подтверждена', ['user' => new UserResource($user->load('yandexMusicConnection'))]);
        }

        return $this->successResponse('Ссылка подтверждения отправлена');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->auth->sendPasswordResetLink($request->validated('email'));

        return $this->successResponse('Если эта почта зарегистрирована, мы отправили ссылку для восстановления пароля');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        if (! $this->auth->resetPassword(ResetPasswordData::fromArray($request->validated()))) {
            return $this->errorResponse('Ссылка недействительна или устарела', 422);
        }

        return $this->successResponse('Пароль успешно обновлен');
    }
}
