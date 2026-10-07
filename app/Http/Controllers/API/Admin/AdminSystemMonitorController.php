<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AdminSystemMonitorController extends Controller
{
    public function show(): JsonResponse
    {
        $path = '/var/lib/sonetcord-monitor/status.json';
        if (! is_readable($path)) {
            return $this->errorResponse('Мониторинг ещё не запущен', 503);
        }

        try {
            $snapshot = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $snapshot['stale'] = time() - strtotime($snapshot['updated_at']) > 180;
        } catch (\Throwable) {
            return $this->errorResponse('Не удалось прочитать состояние мониторинга', 503);
        }

        return $this->successResponse('Состояние системы', ['monitor' => $snapshot])
            ->header('Cache-Control', 'no-store, private');
    }
}
