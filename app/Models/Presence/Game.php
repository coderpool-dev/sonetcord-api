<?php

namespace App\Models\Presence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $table = 'games';

    /** slug => canonical display name for legacy / process-style names. null = не игра. */
    private const DISPLAY_ALIASES = [
        'project_highschool' => 'Agefield High: Rock the School',
        'project highschool' => 'Agefield High: Rock the School',
        'project_highschool-win64-shipping' => 'Agefield High: Rock the School',
        'pendriverpro' => 'Pacific Drive',
        'pendriverpro-win64-shipping' => 'Pacific Drive',
        'b1' => 'Black Myth: Wukong',
        'b1-win64-shipping' => 'Black Myth: Wukong',
        'helldiverst 2' => 'Helldivers 2',
        'helldivers™ 2' => 'Helldivers 2',
        'security51' => 'Security 51',
        'dead island - definitive edition' => 'Dead Island Definitive Edition',
        'gpu drivers are out of date' => null,
        'abinfinite' => 'Arena Breakout: Infinite',
        'ab infinite' => 'Arena Breakout: Infinite',
        'arena breakout infinite' => 'Arena Breakout: Infinite',
        'counter-strike 2' => 'Counter-Strike 2',
        'counter strike 2' => 'Counter-Strike 2',
        'counter-strike global offensive' => 'Counter-Strike 2',
        'counter strike global offensive' => 'Counter-Strike 2',
        '7daystodie' => '7 Days to Die',
        '7 days to die' => '7 Days to Die',
        '7 days todie' => '7 Days to Die',
        'dota 2 beta' => 'Dota 2',
        'minecraft' => 'Minecraft',
        'minecraft.windows' => 'Minecraft',
        'minecraft 1.12.2' => 'Minecraft',
        'minecraft* 1.20.1' => 'Minecraft',
        'minecraft* forge 1.20.1' => 'Minecraft',
        // После срезания номера версии: «Minecraft* 1.21.1» → «Minecraft*».
        'minecraft*' => 'Minecraft',
        'minecraft* forge' => 'Minecraft',
        'subnautica2' => 'Subnautica 2',
        'subnautica 2 0.1.2.2-128456' => 'Subnautica 2',
        'stalker2' => 'S.T.A.L.K.E.R. 2: Heart of Chornobyl',
        's.t.a.l.k.e.r. 2 heart of chornobyl' => 'S.T.A.L.K.E.R. 2: Heart of Chornobyl',
        'ready or not' => 'Ready or Not',
        'mafia the old country' => 'Mafia: The Old Country',
        'forzahorizon6' => 'Forza Horizon 6',
        'titan quest 2' => 'Titan Quest II',
        'intothedeadourdarkestdays' => 'Into the Dead: Our Darkest Days',
        'into the dead our darkest days' => 'Into the Dead: Our Darkest Days',
        'coc2' => 'Corruption of Champions II',
        'thelongdark' => 'The Long Dark',
        'blender' => 'Blender',
        'image editor' => 'Blender',
        'blender render' => 'Blender',
        'oxygennotincluded' => 'Oxygen Not Included',
        'slimerancher2' => 'Slime Rancher 2',
        'genshin impact' => 'Genshin Impact',
        'genshinimpact' => 'Genshin Impact',
        'genshin impact game' => 'Genshin Impact',
        'yuanshen' => 'Genshin Impact',
        'yuan shen' => 'Genshin Impact',
        '原神' => 'Genshin Impact',
        'pubg' => 'PUBG: BATTLEGROUNDS',
        'tslgame' => 'PUBG: BATTLEGROUNDS',
        // Внутренние имена exe: BBQ-Win64-Shipping / KZ — The First Berserker: Khazan.
        'bbq' => 'The First Berserker: Khazan',
        'kz' => 'The First Berserker: Khazan',
        'goatsim ue4' => 'Goat Simulator: Remastered',
        'goatsim_ue4' => 'Goat Simulator: Remastered',
        'compile error' => null,
        'goydacord' => null,
        'goida cord' => null,
        'epic online services' => null,
        'dayz uninstaller' => null,
        'wallpaper ui' => null,
        'wallpaper_engine' => null,
        'wallpaper engine' => null,
        'crosshair v2' => null,
        'crosshairv2qgikt' => null,
    ];

    /** Точные названия, которые вообще не игры (служебные окна/процессы). */
    private const NON_GAME_EXACT = [
        'shproto',
        'settings',
        'parameters',
        'program manager',
        'task manager',
        'steam',
        'discord',
        'epic games launcher',
        'riot client',
        'ubisoft connect',
        'battle.net',
    ];

    /** Начала строк, которые не являются названием игры. */
    private const NON_GAME_PREFIXES = [
        'renderer to use',
        'no compatible gpu',
        'untitled',
        // Оверлей Epic Online Services (EOSOverlayRenderer-Win64-Shipping) рядом с играми из EGS.
        'eosoverlay',
    ];

    protected $fillable = [
        'slug',
        'name',
    ];

    public function sessions(): HasMany
    {
        return $this->hasMany(GameSession::class);
    }

    public static function slugFromName(string $gameName): string
    {
        $normalized = self::canonicalizeName($gameName);

        return mb_strtolower($normalized);
    }

    /**
     * Приводит сырое имя (заголовок окна / имя процесса / папки) к чистому названию игры.
     * Возвращает null, если это вообще не игра (лаунчер, служебное окно, мусорный заголовок).
     */
    public static function normalizePublicName(string $gameName): ?string
    {
        $normalized = self::canonicalizeName($gameName);
        if ($normalized === '') {
            return null;
        }

        $slug = mb_strtolower($normalized);
        if (array_key_exists($slug, self::DISPLAY_ALIASES)) {
            // Алиас может быть null (например, «Epic Online Services» — это не игра).
            return self::DISPLAY_ALIASES[$slug];
        }

        if (self::isJunkName($normalized)) {
            return null;
        }

        return $normalized;
    }

    public static function resolveByName(string $gameName): self
    {
        $normalized = self::normalizePublicName($gameName);
        if ($normalized === null || $normalized === '') {
            throw new \InvalidArgumentException('Invalid game name');
        }

        $game = self::query()->where('slug', self::slugFromName($normalized))->first()
            ?? self::findByCompactName($normalized)
            ?? self::query()->create(['slug' => self::slugFromName($normalized), 'name' => $normalized]);

        if (self::isBetterDisplayName($normalized, $game->name)) {
            $game->update(['name' => $normalized]);
        }

        return $game->fresh();
    }

    /**
     * Ключ для сравнения без пробелов, регистра и знаков: «EscapeFromTarkov» и «Escape from Tarkov»,
     * «Thequarry» и «The Quarry» — одна игра, просто одно имя пришло из exe, другое из заголовка окна.
     */
    public static function compactKey(string $name): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name));
    }

    /** Таблица игр маленькая (десятки строк), поэтому ищем прямым перебором. */
    private static function findByCompactName(string $name): ?self
    {
        $key = self::compactKey($name);

        return $key === '' ? null : self::query()->get()->first(fn (self $game) => self::compactKey($game->name) === $key);
    }

    private static function canonicalizeName(string $gameName): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($gameName)) ?? '';
        $normalized = preg_replace('/\s*\((?:inactive|disabled|неактивно|paused)\)\s*$/iu', '', $normalized) ?? $normalized;
        // Номер сборки в заголовке окна: «Subnautica 2 0.1.2.2-128456», «Minecraft 1.20.1».
        $normalized = preg_replace('/\s+v?\d+(?:\.\d+){2,}(?:[-+][\w.]+)?$/u', '', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        if (self::looksLikeBlender($normalized)) {
            return 'Blender';
        }

        return $normalized;
    }

    /**
     * Мусор, который иногда прилетает от детекции на десктопе: лаунчеры, заголовки окон
     * с путями, служебные процессы, длинные описательные подсказки.
     */
    private static function isJunkName(string $name): bool
    {
        $lower = mb_strtolower($name);

        if (in_array($lower, self::NON_GAME_EXACT, true)) {
            return true;
        }

        foreach (self::NON_GAME_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        // «7 Days To Die Launcher», «DayZ Launcher» — это лаунчер, а не игра.
        if (preg_match('/\blauncher\b/u', $lower)) {
            return true;
        }

        // Деинсталляторы и античиты запускаются рядом с игрой, но сами игрой не являются.
        if (preg_match('/uninstaller|easyanticheat|battleye/u', $lower)) {
            return true;
        }

        // Пути к файлам/окнам редакторов: «Untitled - C:\...\file.blend» и т.п.
        if (preg_match('#[\\\\/]#', $name)) {
            return true;
        }

        // Служебные процессы падений.
        if (preg_match('/\bcrash\s*(reporter|handler)\b|crashpad|werfault|wermgr/u', $lower)) {
            return true;
        }

        // Длинные описательные строки-подсказки («Renderer to use, can affect GPU…»).
        // Осторожно: у реальных игр бывают длинные имена (напр. «S.T.A.L.K.E.R. 2:
        // Heart of Chornobyl»), поэтому ориентируемся на запятые/предложение, а не
        // на число слов.
        if (mb_strlen($name) > 48 && str_contains($name, ',')) {
            return true;
        }

        return false;
    }

    private static function looksLikeBlender(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        if (preg_match('/\.blend\b/iu', $name)) {
            return true;
        }

        if (preg_match('/\bblender\b/iu', $name)) {
            return true;
        }

        return (bool) preg_match(
            '/^(image editor|blender render|uv editor|shader editor|geometry nodes|video sequencer|compositor|outliner|properties)$/iu',
            $name
        );
    }

    /** Имя с двоеточием, с пробелами или заметно длиннее выглядит в интерфейсе лучше. */
    public static function isBetterDisplayName(string $candidate, string $current): bool
    {
        if ($candidate === $current) {
            return false;
        }

        if (str_contains($candidate, ':') && ! str_contains($current, ':')) {
            return true;
        }

        if (str_contains($candidate, ' ') && ! str_contains($current, ' ')) {
            return true;
        }

        return mb_strlen($candidate) > mb_strlen($current) + 2;
    }
}
