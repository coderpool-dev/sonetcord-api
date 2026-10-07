<?php

use App\Http\Controllers\API\Account\AuthController;
use App\Http\Controllers\API\Account\DemoGuestController;
use App\Http\Controllers\API\Conversations\AttachmentController;
use App\Http\Controllers\API\Integrations\GameIconController;
use App\Http\Controllers\API\Presence\SitePresenceController;
use App\Http\Controllers\API\Servers\ServerInviteController;
use App\Http\Controllers\API\Support\FeedbackController;
use App\Http\Controllers\API\Support\SupportAttachmentController;
use Illuminate\Support\Facades\Route;

// Вход, регистрация, восстановление пароля
Route::controller(AuthController::class)->name('auth.')->group(function () {
    Route::post('login', 'login')->middleware('throttle:login')->name('login');
    Route::post('register', 'register')->middleware(['throttle:register', 'captcha'])->name('register');
    Route::post('forgot-password', 'forgotPassword')->middleware(['throttle:forgot-password', 'captcha'])->name('password.forgot');
    Route::post('reset-password', 'resetPassword')->middleware('throttle:reset-password')->name('password.reset');
});

// Демо-вход с лендинга: временный аккаунт на сутки (DemoGuestService).
Route::post('demo', [DemoGuestController::class, 'store'])->middleware(['throttle:demo', 'captcha'])->name('demo.start');

// Ссылка из письма. Имя роута Laravel использует для подписанных ссылок подтверждения.
Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
    ->whereNumber('id')
    ->middleware(['signed', 'throttle:verify-email'])
    ->name('verification.verify');

// Публичные эндпоинты: проверка связи, счётчик посетителей, форма обратной связи.
Route::get('ping', fn () => response()->json(['pong' => true]))->middleware('throttle:ping')->name('ping');
Route::post('presence/ping', [SitePresenceController::class, 'ping'])
    ->middleware('throttle:presence')
    ->name('presence.ping');
Route::post('feedback', [FeedbackController::class, 'store'])
    ->middleware('throttle:feedback')
    ->name('feedback.store');
Route::get('games/icons/lookup', [GameIconController::class, 'show'])
    ->middleware('throttle:game-icons')
    ->name('games.icons.lookup');

// Превью приглашения на сервер — без логина, для публичной страницы /invite/{code}. Без мутаций.
Route::get('invites/{code}', [ServerInviteController::class, 'preview'])
    ->middleware('throttle:invite-preview')
    ->name('invites.preview');

// Картинки в чате браузер загружает сам, через <img>, и токен к такому запросу не приложить.
// Поэтому вместо авторизации — подпись в адресе: без неё файл не отдаётся.
Route::middleware('signed')->group(function () {
    Route::get('attachments/{message}', [AttachmentController::class, 'show'])->name('attachments.show');
    Route::get('support/attachments/{message}/{index?}', [SupportAttachmentController::class, 'show'])
        ->whereNumber('index')
        ->name('support.attachments.show');
});
