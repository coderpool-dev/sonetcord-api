<?php

namespace App\Http\Controllers\API\Support;

use App\Data\UserReportData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\StoreReportRequest;
use App\Services\Presence\GeoIpService;
use App\Services\Support\UserReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly UserReportService $reports,
        private readonly GeoIpService $geoIp,
    ) {}

    public function store(StoreReportRequest $request): JsonResponse
    {
        $this->reports->submit(
            $request->user(),
            UserReportData::fromArray($request->validated()),
            $this->geoIp->resolveClientIp($request),
        );

        return $this->successResponse('Жалоба отправлена', [], 201);
    }
}
