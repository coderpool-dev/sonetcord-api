<?php

use App\Http\Controllers\API\Admin;
use Illuminate\Support\Facades\Route;

// Админка
Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
    Route::get('network-latency', [Admin\AdminNetworkLatencyController::class, 'show'])->name('network-latency.show');
    Route::get('client-logs', [Admin\AdminClientLogsController::class, 'show'])->name('client-logs.show');
    Route::get('system-monitor', [Admin\AdminSystemMonitorController::class, 'show'])->name('system-monitor.show');
    Route::get('users', [Admin\UserController::class, 'index'])->name('users.index');
    Route::get('stats', [Admin\StatsController::class, 'show'])->name('stats.show');
    Route::get('activity', [Admin\ActivityController::class, 'show'])->name('activity.show');
    Route::get('calls', [Admin\ActivityController::class, 'calls'])->name('calls.index');

    Route::prefix('game-icon-submissions')->name('game-icon-submissions.')
        ->controller(Admin\GameIconSubmissionController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('{submission}/preview', 'preview')->name('preview');
            Route::post('{submission}/approve', 'approve')->name('approve');
            Route::post('{submission}/reject', 'reject')->name('reject');
        });

    Route::prefix('support')->name('support.')->controller(Admin\SupportThreadController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{thread}', 'show')->name('show');
        Route::post('{thread}/messages', 'storeMessage')->name('messages.store');
        Route::patch('{thread}', 'update')->name('update');
    });

    Route::prefix('reports')->name('reports.')->controller(Admin\ReportController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::patch('{report}', 'update')->name('update');
    });

    Route::prefix('feedback')->name('feedback.')->controller(Admin\FeedbackController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::patch('{feedback}', 'update')->name('update');
        Route::delete('{feedback}', 'destroy')->name('destroy');
    });

    Route::prefix('admins')->name('admins.')->controller(Admin\AdministratorController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::delete('{user}', 'destroy')->name('destroy');
    });
});
