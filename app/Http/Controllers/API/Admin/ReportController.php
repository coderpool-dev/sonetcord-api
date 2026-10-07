<?php

namespace App\Http\Controllers\API\Admin;

use App\Data\AdminListFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListReportsRequest;
use App\Http\Requests\Admin\UpdateReportStatusRequest;
use App\Models\Support\UserReport;
use App\Services\Support\UserReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(private readonly UserReportService $reports) {}

    public function index(ListReportsRequest $request): JsonResponse
    {
        return $this->successResponse('Жалобы на пользователей', $this->reports->listForAdmin(AdminListFilters::fromArray($request->validated())));
    }

    public function update(UpdateReportStatusRequest $request, UserReport $report): JsonResponse
    {
        $report->update(['status' => $request->validated('status')]);

        return $this->successResponse('Обновлено', [
            'report' => $report->load(['reporter', 'target'])->toAdminArray(),
        ]);
    }
}
