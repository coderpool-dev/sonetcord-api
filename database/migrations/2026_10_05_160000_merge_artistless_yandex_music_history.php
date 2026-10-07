<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ynison иногда присылал трек без исполнителя, и у той же песни появлялась вторая запись истории
 * с ключом «|название». Сливаем такие записи с записью той же песни с исполнителем.
 */
return new class extends Migration
{
    public function up(): void
    {
        $artistless = DB::table('yandex_music_track_histories')->where('track_key', 'like', '|%')->get();

        foreach ($artistless as $row) {
            $targets = DB::table('yandex_music_track_histories')
                ->where('user_id', $row->user_id)
                ->where('id', '!=', $row->id)
                ->where('track_key', 'like', '%'.addcslashes($row->track_key, '%_\\'))
                ->where('artist', '!=', '')
                ->orderByDesc('last_played_at')
                ->get();

            // Одно и то же название у нескольких исполнителей — не угадываем, оставляем как есть.
            if ($targets->count() !== 1) {
                continue;
            }

            $target = $targets->first();

            DB::table('yandex_music_track_histories')->where('id', $target->id)->update([
                'play_count' => $target->play_count + $row->play_count,
                'first_played_at' => collect([$target->first_played_at, $row->first_played_at])->filter()->min(),
                'last_played_at' => collect([$target->last_played_at, $row->last_played_at])->filter()->max(),
                'cover_url' => $target->cover_url ?? $row->cover_url,
                'track_url' => $target->track_url ?? $row->track_url,
            ]);
            DB::table('yandex_music_track_histories')->where('id', $row->id)->delete();
        }
    }

    public function down(): void
    {
        // Слитые записи не восстановить.
    }
};
