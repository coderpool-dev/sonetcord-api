<?php

namespace Tests\Feature;

use App\Models\Integrations\GameIcon;
use App\Models\Presence\Game;
use App\Models\Presence\GameSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_exe_spelling_joins_the_game_detected_from_the_window_title(): void
    {
        $game = Game::resolveByName('Escape from Tarkov');

        $this->assertSame($game->id, Game::resolveByName('EscapeFromTarkov')->id);
        $this->assertSame($game->id, Game::resolveByName('escape from tarkov')->id);
        $this->assertSame('Escape from Tarkov', $game->fresh()->name);
        $this->assertSame(1, Game::query()->count());
    }

    public function test_window_title_spelling_improves_the_name_created_from_the_exe(): void
    {
        Game::resolveByName('Thequarry');

        $this->assertSame('The Quarry', Game::resolveByName('The Quarry')->name);
        $this->assertSame(1, Game::query()->count());
    }

    public function test_normalize_command_merges_spellings_and_drops_non_games(): void
    {
        $user = User::factory()->create();
        $title = Game::query()->create(['slug' => 'escape from tarkov', 'name' => 'Escape from Tarkov']);
        $exe = Game::query()->create(['slug' => 'escapefromtarkov', 'name' => 'EscapeFromTarkov']);
        $overlay = Game::query()->create(['slug' => 'eosoverlayrenderer', 'name' => 'Eosoverlayrenderer']);
        foreach ([$title, $exe, $overlay] as $game) {
            GameSession::query()->create(['user_id' => $user->id, 'game_id' => $game->id, 'started_at' => now()->subHour(), 'ended_at' => now()]);
        }

        $this->artisan('games:normalize')->assertSuccessful();

        $this->assertSame(['Escape from Tarkov'], Game::query()->pluck('name')->all());
        $this->assertSame(2, GameSession::query()->where('game_id', $title->id)->count());
        $this->assertSame(2, GameSession::query()->count());
    }

    public function test_icon_uploaded_under_the_exe_name_is_found_for_the_title(): void
    {
        $icon = GameIcon::query()->create(['slug' => 'thelongdark', 'name' => 'TheLongDark', 'file' => 'thelongdark.png']);

        $this->assertTrue($icon->is(GameIcon::findByName('The Long Dark')));
        $this->assertNull(GameIcon::findByName('The Quarry'));
    }
}
