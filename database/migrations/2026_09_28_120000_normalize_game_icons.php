<?php

use App\Models\Integrations\GameIcon;
use App\Models\Presence\Game;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Иконки игр после проверки глазами (2026-09-28):
 *  - у 7 Days to Die была чужая синяя иконка, а настоящий логотип лежал под заголовком окна
 *    настроек игры («Renderer to use, …»);
 *  - у Rust — та же чужая иконка, у Ready or Not — логотип Epic Games (иконка лаунчера EOS):
 *    убираем, десктоп заново вытащит иконку из exe при следующем запуске игры;
 *  - остальные строки сводим к каноническому названию: одна иконка на игру, мусор удаляем.
 */
return new class extends Migration
{
    private const REAL_7DTD_ICON_SLUG = 'renderer-to-use-can-affect-gpudriver-compatibility-and-performance';

    private const WRONG_ICON_SLUGS = ['rust', 'ready-or-not'];

    public function up(): void
    {
        $this->useReal7DaysToDieIcon();

        foreach (self::WRONG_ICON_SLUGS as $slug) {
            $this->deleteIcon(DB::table('game_icons')->where('slug', $slug)->first());
        }

        $groups = [];
        foreach (DB::table('game_icons')->orderByDesc('source_priority')->orderByDesc('updated_at')->get() as $icon) {
            $canonical = Game::normalizePublicName((string) $icon->name);
            if ($canonical === null || $canonical === '') {
                $this->deleteIcon($icon);

                continue;
            }
            $groups[GameIcon::slugFromName($canonical)][] = [$icon, $canonical];
        }

        foreach ($groups as $slug => $icons) {
            $keeper = collect($icons)->first(fn (array $entry) => $entry[0]->slug === $slug) ?? $icons[0];
            [$keep, $canonical] = $keeper;

            DB::table('game_icons')->where('id', $keep->id)->update(['slug' => $slug, 'name' => $canonical, 'updated_at' => now()]);

            foreach ($icons as [$icon]) {
                if ($icon->id !== $keep->id) {
                    $this->deleteIcon($icon);
                }
            }
        }
    }

    public function down(): void
    {
        // Необратимо: мусорные строки и неверные файлы иконок удалены.
    }

    private function useReal7DaysToDieIcon(): void
    {
        $real = DB::table('game_icons')->where('slug', self::REAL_7DTD_ICON_SLUG)->first();
        $current = DB::table('game_icons')->where('slug', '7-days-to-die')->first();
        if (! $real || ! $current) {
            return;
        }

        DB::table('game_icons')->where('id', $current->id)->update(['file' => $real->file, 'icon_hash' => $real->icon_hash, 'updated_at' => now()]);
        DB::table('game_icons')->where('id', $real->id)->delete();
        $this->deleteFileIfUnused((string) $current->file);
    }

    private function deleteIcon(?object $icon): void
    {
        if (! $icon) {
            return;
        }

        DB::table('game_icons')->where('id', $icon->id)->delete();
        $this->deleteFileIfUnused((string) $icon->file);
    }

    private function deleteFileIfUnused(string $file): void
    {
        if ($file !== '' && ! DB::table('game_icons')->where('file', $file)->exists()) {
            Storage::disk('public')->delete('game-icons/'.ltrim($file, '/'));
        }
    }
};
