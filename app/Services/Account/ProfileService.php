<?php

namespace App\Services\Account;

use App\Data\ProfileUpdateResult;
use App\Data\UpdateProfileData;
use App\Events\UserProfileUpdated;
use App\Models\User;
use App\Services\Presence\ActivityService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProfileService
{
    /** Поля, которые видят другие пользователи: их изменение рассылаем в общие каналы. */
    private const PUBLIC_FIELDS = [
        'avatar', 'name', 'banner', 'presence', 'status_emoji', 'status_text', 'game_status_text', 'music_status_text',
    ];

    public function __construct(private readonly ActivityService $activityService) {}

    public function update(
        User $user,
        UpdateProfileData $validated,
        ?UploadedFile $avatar = null,
        ?UploadedFile $banner = null,
    ): ProfileUpdateResult {
        $updateData = $this->attributesFromInput($user, $validated);

        if ($avatar !== null) {
            $updateData['avatar'] = $this->storeImage($avatar, 'avatars', $user->id.'.jpg', $user->avatar, 'default.png');
        }

        if ($banner !== null) {
            $updateData['banner'] = $this->storeImage($banner, 'banners', $user->id.'.jpg', $user->banner);
        } elseif ($validated->removeBanner ?? false) {
            $this->deletePublicFile('banners', $user->banner);
            $updateData['banner'] = null;
        }

        if ($validated->clearGameStatus ?? false) {
            $this->activityService->recordGameEnd($user);
        }

        if ($updateData === []) {
            return new ProfileUpdateResult($user->load('yandexMusicConnection'), false);
        }

        $user->update($updateData);
        $user->refresh()->load('yandexMusicConnection');

        if (array_key_exists('email_verified_at', $updateData) && $user->email_verified_at === null) {
            $user->sendEmailVerificationLink();
        }

        if (array_intersect(self::PUBLIC_FIELDS, array_keys($updateData)) !== []) {
            broadcast(new UserProfileUpdated($user, $user->activeChannelIds()));
        }

        return new ProfileUpdateResult($user, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFromInput(User $user, UpdateProfileData $validated): array
    {
        // null означает «поле не передано»: такие поля не трогаем.
        $updateData = array_filter(
            ['name' => $validated->name, 'date' => $validated->date, 'presence' => $validated->presence],
            fn ($value) => $value !== null,
        );

        if (isset($validated->email)) {
            $updateData['email'] = $validated->email;

            if (strcasecmp($validated->email, $user->email) !== 0) {
                $updateData['email_verified_at'] = null;
            }
        }

        if (isset($validated->currentPassword, $validated->newPassword)) {
            if (! Hash::check($validated->currentPassword, $user->password)) {
                throw ValidationException::withMessages(['current_password' => ['Текущий пароль неверен']]);
            }

            $updateData['password'] = Hash::make($validated->newPassword);
        }

        if ($validated->has('banner_color')) {
            $updateData['banner_color'] = $validated->bannerColor ?: null;
        }

        if ($validated->clearStatus ?? false) {
            $updateData['status_emoji'] = null;
            $updateData['status_text'] = null;
        } else {
            if ($validated->has('status_emoji')) {
                $updateData['status_emoji'] = $validated->statusEmoji ?: null;
            }
            if ($validated->has('status_text')) {
                $updateData['status_text'] = $validated->statusText ?: null;
            }
        }

        if ($validated->clearGameStatus ?? false) {
            $updateData['game_status_text'] = null;
            $updateData['game_status_synced_at'] = null;
        } elseif ($validated->has('game_status_text')) {
            $updateData['game_status_text'] = $validated->gameStatusText ?: null;
            $updateData['game_status_synced_at'] = $validated->gameStatusText ? now() : null;
        }

        return $updateData;
    }

    private function storeImage(
        UploadedFile $file,
        string $directory,
        string $fileName,
        ?string $previous,
        ?string $keepName = null,
    ): string {
        if (! $file->isValid()) {
            throw new RuntimeException('Загруженный файл повреждён: '.$file->getErrorMessage());
        }

        if ($previous && $previous !== $keepName) {
            $this->deletePublicFile($directory, $previous);
        }

        if ($file->storeAs($directory, $fileName, 'public') === false) {
            throw new RuntimeException("Не удалось сохранить файл на диск. Проверьте права на storage/app/public/{$directory}/");
        }

        return $fileName;
    }

    private function deletePublicFile(string $directory, ?string $name): void
    {
        if ($name) {
            Storage::disk('public')->delete($directory.'/'.$name);
        }
    }
}
