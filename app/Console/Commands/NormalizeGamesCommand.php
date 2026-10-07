<?php

namespace App\Console\Commands;

use App\Models\Presence\Game;
use App\Models\Presence\GameSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeGamesCommand extends Command
{
    protected $signature = 'games:normalize {--dry-run : Показать что будет сделано, но ничего не менять}';

    protected $description = 'Чистит таблицу игр: убирает мусор (лаунчеры, служебные окна) и склеивает дубликаты';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Режим предпросмотра — ничего не меняется.');
        }

        $games = Game::query()->orderBy('id')->get(['id', 'slug', 'name']);
        $this->info("Всего игр в базе: {$games->count()}");

        $junk = [];              // game_id => name (удаляем вместе с сессиями)
        $canonicalGroups = [];   // canonicalSlug => ['display' => string, 'ids' => [gameId, ...]]

        foreach ($games as $game) {
            $canonicalName = Game::normalizePublicName((string) $game->name);

            if ($canonicalName === null || $canonicalName === '') {
                $junk[$game->id] = $game->name;

                continue;
            }

            // Одна группа для «EscapeFromTarkov» и «Escape from Tarkov»: сравниваем без пробелов и знаков.
            $groupKey = Game::compactKey($canonicalName);
            if (! isset($canonicalGroups[$groupKey])) {
                $canonicalGroups[$groupKey] = ['display' => $canonicalName, 'ids' => []];
            }
            // Более «красивое» имя (с двоеточием/пробелами) оставляем как отображаемое.
            if (Game::isBetterDisplayName($canonicalName, $canonicalGroups[$groupKey]['display'])) {
                $canonicalGroups[$groupKey]['display'] = $canonicalName;
            }
            $canonicalGroups[$groupKey]['ids'][] = $game->id;
        }

        // 1) Мусор.
        $junkSessions = GameSession::query()->whereIn('game_id', array_keys($junk))->count();
        $this->line('');
        $this->info('Мусорные записи ('.count($junk)." игр, {$junkSessions} сессий будут удалены):");
        foreach ($junk as $id => $name) {
            $this->line("  ✗ [{$id}] {$name}");
        }

        // 2) Дубликаты.
        $duplicateGroups = array_filter($canonicalGroups, fn ($group) => count($group['ids']) > 1);
        $this->line('');
        $this->info('Дубликаты для склейки ('.count($duplicateGroups).' групп):');
        foreach ($duplicateGroups as $slug => $group) {
            $names = Game::query()->whereIn('id', $group['ids'])->pluck('name')->all();
            $this->line("  → «{$group['display']}»  ←  ".implode(' | ', $names));
        }

        if ($dryRun) {
            $this->line('');
            $this->warn('Предпросмотр завершён. Запусти без --dry-run, чтобы применить.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($junk, $canonicalGroups): void {
            // Удаляем мусор (сессии уйдут каскадом по FK).
            if ($junk !== []) {
                Game::query()->whereIn('id', array_keys($junk))->delete();
            }

            foreach ($canonicalGroups as $group) {
                $ids = $group['ids'];
                $keptGameId = $this->pickGameToKeep($ids, $group['display']);

                // Переносим сессии дубликатов на оставляемую игру и удаляем дубликаты.
                $duplicateIds = array_values(array_filter($ids, fn ($id) => $id !== $keptGameId));
                if ($duplicateIds !== []) {
                    GameSession::query()->whereIn('game_id', $duplicateIds)->update(['game_id' => $keptGameId]);
                    Game::query()->whereIn('id', $duplicateIds)->delete();
                }

                // Приводим имя и slug оставляемой игры к каноничному виду.
                $keptGame = Game::query()->find($keptGameId);
                if ($keptGame) {
                    $canonicalSlug = Game::slugFromName($group['display']);
                    $updates = [];
                    if ($keptGame->name !== $group['display']) {
                        $updates['name'] = $group['display'];
                    }
                    if ($keptGame->slug !== $canonicalSlug
                        && ! Game::query()->where('slug', $canonicalSlug)->where('id', '!=', $keptGameId)->exists()) {
                        $updates['slug'] = $canonicalSlug;
                    }
                    if ($updates !== []) {
                        $keptGame->update($updates);
                    }
                }
            }
        });

        $this->line('');
        $this->info('Готово. Игр осталось: '.Game::query()->count());

        return self::SUCCESS;
    }

    /**
     * @param  array<int>  $ids
     */
    private function pickGameToKeep(array $ids, string $canonicalName): int
    {
        // Предпочитаем игру, чьё имя уже совпадает с каноничным; иначе — с наибольшим числом сессий.
        $alreadyCanonical = Game::query()->whereIn('id', $ids)->where('name', $canonicalName)->orderBy('id')->first();
        if ($alreadyCanonical) {
            return (int) $alreadyCanonical->id;
        }

        $mostPlayed = GameSession::query()
            ->selectRaw('game_id, COUNT(*) as sessions_count')
            ->whereIn('game_id', $ids)
            ->groupBy('game_id')
            ->orderByDesc('sessions_count')
            ->first();

        return $mostPlayed ? (int) $mostPlayed->game_id : (int) min($ids);
    }
}
