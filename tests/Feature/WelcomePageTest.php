<?php

namespace Tests\Feature;

use Tests\TestCase;

class WelcomePageTest extends TestCase
{
    public function test_download_button_points_to_current_installer(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('https://sonetcord.ru/downloads/windows', false);
    }

    public function test_old_installer_link_redirects_to_current_version(): void
    {
        $this->get('/downloads/SonetCord-Setup-1.0.0.exe')
            ->assertStatus(301)
            ->assertRedirect('https://sonetcord.ru/downloads/windows');
    }
}
