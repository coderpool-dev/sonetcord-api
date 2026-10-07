<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Services\NetworkLatencyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminNetworkLatencyController extends Controller
{
    public function show(Request $request, NetworkLatencyService $service)
    {
        $data = $request->validate(['metric' => ['sometimes', Rule::in(['api', 'voice'])], 'user_id' => ['sometimes', 'integer', 'min:1']]);

        return $this->successResponse('Задержка соединений', $service->snapshot($data['metric'] ?? 'api', $data['user_id'] ?? null))
            ->header('Cache-Control', 'no-store, private');
    }
}
