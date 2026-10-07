<?php

namespace App\Http\Controllers\API\Admin;

use App\Data\ClientLogFilters;
use App\Http\Controllers\Controller;
use App\Services\ClientDiagnosticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminClientLogsController extends Controller
{
    public function show(Request $request, ClientDiagnosticsService $diagnostics): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:'.now('Europe/Moscow')->subDays(7)->toDateString(), 'before_or_equal:'.now('Europe/Moscow')->toDateString()],
            'user_id' => ['sometimes', 'integer', 'min:1'], 'session_id' => ['sometimes', 'uuid'],
            'level' => ['sometimes', Rule::in(['info', 'warning', 'error'])],
            'event' => ['sometimes', Rule::in(ClientDiagnosticsService::EVENTS)],
        ]);

        return $this->successResponse('Клиентские логи', $diagnostics->recent(ClientLogFilters::fromArray($filters)))
            ->header('Cache-Control', 'no-store, private');
    }
}
