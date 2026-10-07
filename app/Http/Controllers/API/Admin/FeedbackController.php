<?php

namespace App\Http\Controllers\API\Admin;

use App\Data\AdminListFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListFeedbackRequest;
use App\Http\Requests\Admin\UpdateFeedbackStatusRequest;
use App\Models\Support\FeedbackMessage;
use App\Services\Support\FeedbackService;
use Illuminate\Http\JsonResponse;

class FeedbackController extends Controller
{
    public function __construct(private readonly FeedbackService $feedback) {}

    public function index(ListFeedbackRequest $request): JsonResponse
    {
        return $this->successResponse('Обращения с сайта', $this->feedback->listForAdmin(AdminListFilters::fromArray($request->validated())));
    }

    public function update(UpdateFeedbackStatusRequest $request, FeedbackMessage $feedback): JsonResponse
    {
        $feedback->update(['status' => $request->validated('status')]);

        return $this->successResponse('Обновлено', ['message_item' => $feedback->toAdminArray()]);
    }

    public function destroy(FeedbackMessage $feedback): JsonResponse
    {
        $feedback->delete();

        return $this->successResponse('Обращение удалено');
    }
}
