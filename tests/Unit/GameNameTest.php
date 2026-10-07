<?php

namespace Tests\Unit;

use App\Models\Presence\Game;
use PHPUnit\Framework\TestCase;

class GameNameTest extends TestCase
{
    public function test_genshin_aliases_normalize_to_display_name(): void
    {
        $this->assertSame('Genshin Impact', Game::normalizePublicName('Genshin Impact'));
        $this->assertSame('Genshin Impact', Game::normalizePublicName('GenshinImpact'));
        $this->assertSame('Genshin Impact', Game::normalizePublicName('YuanShen'));
        $this->assertSame('Genshin Impact', Game::normalizePublicName('原神'));
        $this->assertSame('Genshin Impact', Game::normalizePublicName('Genshin Impact game'));
    }

    public function test_process_aliases_and_driver_warning(): void
    {
        $this->assertSame('Pacific Drive', Game::normalizePublicName('Pendriverpro'));
        $this->assertSame('Black Myth: Wukong', Game::normalizePublicName('b1'));
        $this->assertSame('Helldivers 2', Game::normalizePublicName('HELLDIVERST 2'));
        $this->assertSame('Security 51', Game::normalizePublicName('Security51'));
        $this->assertNull(Game::normalizePublicName('GPU drivers are out of date'));
    }
}
