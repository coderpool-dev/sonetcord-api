<?php

namespace App\Http\Controllers\API\Account;

use App\Data\UpdateProfileData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\Account\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private readonly ProfileService $profiles) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load('yandexMusicConnection');

        return $this->successResponse('Данные профиля успешно получены', [
            'user' => new UserResource($user),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $update = $this->profiles->update(
            $request->user(),
            UpdateProfileData::fromArray($request->validated()),
            $request->file('avatar'),
            $request->file('banner'),
        );

        return $this->successResponse(
            $update->changed ? 'Профиль успешно обновлен' : 'Нет данных для обновления',
            ['user' => new UserResource($update->user)],
        );
    }
}
