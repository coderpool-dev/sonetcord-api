<?php

namespace App\Http\Controllers\API\Support;

use App\Data\FeedbackData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Feedback\StoreFeedbackRequest;
use App\Services\Presence\GeoIpService;
use App\Services\Support\FeedbackService;
use Illuminate\Http\JsonResponse;

class FeedbackController extends Controller
{
    public function __construct(
        private readonly FeedbackService $feedback,
        private readonly GeoIpService $geoIp,
    ) {}

    public function store(StoreFeedbackRequest $request): JsonResponse
    {
        $this->feedback->submit(
            FeedbackData::fromArray($request->validated()),
            // Форма публичная: автора определяем по токену, только если он передан.
            $request->user('sanctum'),
            $this->geoIp->resolveClientIp($request),
            $this->geoIp->countryFromRequest($request),
            $request->userAgent(),
        );

        return $this->successResponse('Обращение отправлено', [], 201);
    }
}
