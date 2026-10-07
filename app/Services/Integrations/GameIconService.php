<?php

namespace App\Services\Integrations;

use App\Events\GameIconUploaded;
use App\Models\Integrations\GameIcon;
use App\Models\Integrations\GameIconSubmission;
use App\Models\Presence\Game;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Пользовательские иконки остаются приватными до решения администратора. */
class GameIconService
{
    private const DIRECTORY = 'game-icons';

    private const SUBMISSIONS_DIRECTORY = 'game-icon-submissions';

    public function lookup(string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Укажите название игры']]);
        }

        $canonicalName = Game::normalizePublicName($name) ?? $name;
        $icon = GameIcon::findByName($canonicalName) ?? GameIcon::findByName($name);

        if (! $icon || ! $this->iconFileExists($icon)) {
            return [
                'icon' => null,
                'exists' => false,
                'slug' => GameIcon::slugFromName($canonicalName),
                'name' => $canonicalName,
                'source_priority' => 0,
                'icon_hash' => null,
            ];
        }

        return [
            'icon' => $this->iconPayload($icon),
            'exists' => true,
            'source_priority' => (int) ($icon->source_priority ?? 1),
            'icon_hash' => $icon->icon_hash,
        ];
    }

    public function submitForReview(User $user, string $name, UploadedFile $file, ?string $source): array
    {
        $canonicalName = Game::normalizePublicName($name);

        if ($canonicalName === null || $canonicalName === '') {
            throw ValidationException::withMessages(['name' => ['Это не игра']]);
        }

        $slug = GameIcon::slugFromName($canonicalName);

        if ($slug === '') {
            throw ValidationException::withMessages(['name' => ['Некорректное название игры']]);
        }

        // Иконка у игры уже есть — новую не принимаем: десктоп каждого игрока присылал свою
        // (из Steam, из exe), и админке копились заявки на игры, у которых всё в порядке.
        $existing = GameIcon::findByName($canonicalName);
        if ($existing && $this->iconFileExists($existing)) {
            return ['icon' => $this->iconPayload($existing), 'exists' => true, 'pending' => false];
        }

        if (! $file->isValid()) {
            throw new RuntimeException('Файл иконки повреждён');
        }

        // Одна заявка на игру: пока её не рассмотрели, другие игроки новую не создают.
        $pending = GameIconSubmission::query()->where('slug', $slug)->where('status', 'pending')->first();
        if ($pending) {
            return ['icon' => null, 'exists' => false, 'pending' => true, 'submission_id' => $pending->id];
        }

        $hash = hash_file('sha256', $file->getRealPath());

        if (GameIconSubmission::query()->where('uploaded_by', $user->id)->where('status', 'pending')->count() >= 20) {
            throw ValidationException::withMessages(['icon' => ['У вас слишком много заявок на проверке']]);
        }

        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => throw ValidationException::withMessages(['icon' => ['Неподдерживаемый формат изображения']]),
        };
        $path = self::SUBMISSIONS_DIRECTORY.'/'.Str::uuid().'.'.$extension;
        if (Storage::disk('local')->put($path, fopen($file->getRealPath(), 'rb')) === false) {
            throw new RuntimeException('Не удалось сохранить заявку на иконку');
        }

        try {
            $submission = GameIconSubmission::query()->create([
                'slug' => $slug,
                'name' => $canonicalName,
                'file' => $path,
                'icon_hash' => $hash,
                'source' => $source,
                'uploaded_by' => $user->id,
                'pending_key' => $user->id.':'.$slug,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return ['icon' => null, 'exists' => false, 'pending' => true, 'submission_id' => $submission->id];
    }

    public function approve(GameIconSubmission $submission, User $reviewer, int $priority): GameIcon
    {
        $publishedPath = null;
        $oldPath = null;

        try {
            $icon = DB::transaction(function () use ($submission, $reviewer, $priority, &$publishedPath, &$oldPath): GameIcon {
                $submission = GameIconSubmission::query()->lockForUpdate()->findOrFail($submission->id);
                if ($submission->status !== 'pending') {
                    throw ValidationException::withMessages(['submission' => ['Заявка уже обработана']]);
                }
                if (! Storage::disk('local')->exists($submission->file)) {
                    throw ValidationException::withMessages(['submission' => ['Файл заявки не найден']]);
                }

                $extension = pathinfo($submission->file, PATHINFO_EXTENSION);
                $publishedPath = self::DIRECTORY.'/'.Str::uuid().'.'.$extension;
                if (! Storage::disk('public')->put($publishedPath, Storage::disk('local')->readStream($submission->file))) {
                    throw new RuntimeException('Не удалось опубликовать иконку');
                }

                $icon = GameIcon::query()->where('slug', $submission->slug)->lockForUpdate()->first();
                $oldPath = $icon?->file ? self::DIRECTORY.'/'.$icon->file : null;
                $icon ??= new GameIcon(['slug' => $submission->slug]);
                $icon->fill([
                    'name' => $submission->name,
                    'file' => basename($publishedPath),
                    'icon_hash' => $submission->icon_hash,
                    'source_priority' => $priority,
                    'uploaded_by' => $submission->uploaded_by,
                ])->save();
                $submission->update([
                    'status' => 'approved',
                    'pending_key' => null,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                ]);

                return $icon;
            });
        } catch (\Throwable $exception) {
            if ($publishedPath) {
                Storage::disk('public')->delete($publishedPath);
            }
            throw $exception;
        }

        Storage::disk('local')->delete($submission->file);
        if ($oldPath && $oldPath !== $publishedPath) {
            Storage::disk('public')->delete($oldPath);
        }
        broadcast(new GameIconUploaded($icon->name, $icon->slug, $icon->iconUrl()));

        return $icon;
    }

    public function reject(GameIconSubmission $submission, User $reviewer): void
    {
        DB::transaction(function () use ($submission, $reviewer): void {
            $submission = GameIconSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($submission->status !== 'pending') {
                throw ValidationException::withMessages(['submission' => ['Заявка уже обработана']]);
            }
            $submission->update([
                'status' => 'rejected',
                'pending_key' => null,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);
        });

        Storage::disk('local')->delete($submission->file);
    }

    /** @return array{slug: string, name: string, icon_url: string} */
    private function iconPayload(GameIcon $icon): array
    {
        return [
            'slug' => $icon->slug,
            'name' => $icon->name,
            'icon_url' => $icon->iconUrl(),
        ];
    }

    private function iconFileExists(GameIcon $icon): bool
    {
        return Storage::disk('public')->exists(self::DIRECTORY.'/'.$icon->file);
    }
}
