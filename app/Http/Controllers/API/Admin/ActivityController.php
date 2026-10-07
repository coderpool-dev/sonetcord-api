<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function __construct(private readonly AdminActivityService $activity) {}

    public function show(): JsonResponse
    {
        return $this->successResponse('Активность', [
            'activity' => $this->activity->snapshot(),
        ]);
    }

    public function calls(Request $request): JsonResponse
    {
        return $this->successResponse('История звонков', $this->activity->callHistory($request->integer('page', 1)));
    }
}
