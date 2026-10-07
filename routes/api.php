<?php

use App\Http\Controllers\API\ClientDiagnosticsController;
use App\Http\Controllers\API\NetworkLatencyController;
use Illuminate\Support\Facades\Route;

Route::get('network-latency/probe', [NetworkLatencyController::class, 'probe'])
    ->middleware(['auth:sanctum', 'throttle:network-latency'])->name('network-latency.probe');
Route::post('network-latency', [NetworkLatencyController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:network-latency'])->name('network-latency.store');
Route::post('client-diagnostics', ClientDiagnosticsController::class)
    ->middleware(['auth:sanctum', 'throttle:client-diagnostics'])->name('client-diagnostics.store');

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
| Здесь видны границы доступа. Маршруты по областям лежат в routes/api/.
| Порядок подключения сохраняет действующие URL и методы клиентов.
*/

require __DIR__.'/api/public.php';

Route::middleware(['auth:sanctum', 'demo.restrict'])->group(function () {
    // Загрузка по частям использует отдельный лимит запросов.
    require __DIR__.'/api/uploads.php';

    Route::middleware('throttle:api')->group(function () {
        // Профиль, сессии и хранилище доступны до подтверждения почты.
        require __DIR__.'/api/account-unverified.php';

        Route::middleware('verified.email')->group(function () {
            require __DIR__.'/api/account.php';
            require __DIR__.'/api/conversations.php';
            require __DIR__.'/api/servers.php';
            require __DIR__.'/api/social.php';
            require __DIR__.'/api/support.php';
            require __DIR__.'/api/integrations.php';
            require __DIR__.'/api/admin.php';
        });
    });
});
