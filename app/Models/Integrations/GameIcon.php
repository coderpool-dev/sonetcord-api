<?php

namespace App\Models\Integrations;

use App\Models\Presence\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GameIcon extends Model
{
    protected $table = 'game_icons';

    /** Приоритет источника иконки: чем выше, тем «настоящее» арт игры. */
    public const SOURCE_PRIORITY = [
        'folder' => 3,
        'folder+norm' => 3,
        'steam' => 2,
        'steam+norm' => 2,
        'exe' => 1,
        'exe+norm' => 1,
    ];

    protected $fillable = [
        'slug',
        'name',
        'file',
        'icon_hash',
        'source_priority',
        'uploaded_by',
    ];

    public static function priorityForSource(?string $source): int
    {
        return self::SOURCE_PRIORITY[(string) $source] ?? 1;
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public static function slugFromName(string $name): string
    {
        return Str::slug(trim($name));
    }

    public function iconUrl(): string
    {
        $url = asset('storage/game-icons/'.ltrim($this->file, '/'));

        if ($this->updated_at) {
            $url .= '?v='.$this->updated_at->timestamp;
        }

        return $url;
    }

    public static function findByName(string $name): ?self
    {
        $raw = trim($name);
        $slugs = [];

        if ($raw !== '') {
            $slugs[] = self::slugFromName($raw);
        }

        $canonical = Game::normalizePublicName($raw);
        if (is_string($canonical) && $canonical !== '') {
            $slugs[] = self::slugFromName($canonical);
        }

        $slugs = array_values(array_unique(array_filter($slugs)));
        if ($slugs === []) {
            return null;
        }

        $exact = self::query()->whereIn('slug', $slugs)->first();
        if ($exact) {
            return $exact;
        }

        // Иконка могла прийти под именем из exe («TheLongDark») — сравниваем без пробелов и знаков.
        $keys = array_map(Game::compactKey(...), [$raw, (string) $canonical]);

        return self::query()->get()->first(fn (self $icon) => in_array(Game::compactKey($icon->slug), $keys, true));
    }
}
