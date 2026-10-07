<?php

namespace Tests\Feature;

use App\Events\GameIconUploaded;
use App\Models\Admin\Privilege;
use App\Models\Integrations\GameIcon;
use App\Models\Integrations\GameIconSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameIconModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Event::fake([GameIconUploaded::class]);
    }

    public function test_user_upload_is_private_until_admin_approves_it(): void
    {
        $uploader = User::factory()->create();
        Sanctum::actingAs($uploader);

        $this->postJson('/api/games/icons', [
            'name' => 'Minecraft',
            'icon' => UploadedFile::fake()->image('icon.jpg', 64, 64),
            'source' => 'folder',
            'hash' => str_repeat('a', 64),
        ])->assertStatus(202)->assertJsonPath('pending', true)->assertJsonPath('exists', false);

        $submission = GameIconSubmission::query()->firstOrFail();
        $this->assertNotSame(str_repeat('a', 64), $submission->icon_hash);
        $this->assertTrue(Storage::disk('local')->exists($submission->file));
        $this->assertSame(0, GameIcon::query()->count());
        $this->getJson('/api/games/icons/lookup?name=Minecraft')->assertJsonPath('exists', false);
        Event::assertNotDispatched(GameIconUploaded::class);

        $this->get("/api/admin/game-icon-submissions/{$submission->id}/preview")->assertForbidden();
        $this->postJson("/api/admin/game-icon-submissions/{$submission->id}/approve", ['source_priority' => 3])->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/admin/game-icon-submissions')->assertOk()->assertJsonCount(1, 'submissions');
        $this->get("/api/admin/game-icon-submissions/{$submission->id}/preview")->assertOk();
        $this->postJson("/api/admin/game-icon-submissions/{$submission->id}/approve", ['source_priority' => 2])
            ->assertOk();

        $icon = GameIcon::query()->firstOrFail();
        $this->assertSame(2, $icon->source_priority);
        $this->assertSame($submission->icon_hash, $icon->icon_hash);
        $this->assertTrue(Storage::disk('public')->exists('game-icons/'.$icon->file));
        $this->assertFalse(Storage::disk('local')->exists($submission->file));
        $this->assertSame('approved', $submission->fresh()->status);
        $this->getJson('/api/games/icons/lookup?name=Minecraft')->assertJsonPath('exists', true);
        Event::assertDispatched(GameIconUploaded::class, 1);
        $this->postJson("/api/admin/game-icon-submissions/{$submission->id}/approve", ['source_priority' => 3])
            ->assertUnprocessable();
    }

    public function test_rejection_removes_private_file_and_publishes_nothing(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/games/icons', [
            'name' => 'Minecraft', 'icon' => UploadedFile::fake()->image('new.png', 64, 64),
            'source' => 'folder',
        ])->assertStatus(202)->assertJsonPath('exists', false);
        $submission = GameIconSubmission::query()->firstOrFail();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/admin/game-icon-submissions/{$submission->id}/reject")->assertOk();

        $this->assertSame(0, GameIcon::query()->count());
        $this->assertFalse(Storage::disk('local')->exists($submission->file));
        $this->assertSame('rejected', $submission->fresh()->status);
        Event::assertNotDispatched(GameIconUploaded::class);
    }

    public function test_upload_for_game_that_already_has_icon_creates_no_submission(): void
    {
        Storage::disk('public')->put('game-icons/old.png', 'old image');
        GameIcon::query()->create([
            'slug' => 'minecraft', 'name' => 'Minecraft', 'file' => 'old.png',
            'icon_hash' => str_repeat('0', 64), 'source_priority' => 1,
        ]);
        Sanctum::actingAs(User::factory()->create());

        foreach (['Minecraft', 'Minecraft 1.21.4'] as $name) {
            $this->postJson('/api/games/icons', [
                'name' => $name, 'icon' => UploadedFile::fake()->image('new.webp', 64, 64), 'source' => 'steam',
            ])->assertOk()->assertJsonPath('exists', true)->assertJsonPath('pending', false);
        }

        $this->assertSame(0, GameIconSubmission::query()->count());
        $this->assertSame('old.png', GameIcon::query()->firstOrFail()->file);
    }

    public function test_one_pending_submission_per_game_across_players(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $first = $this->postJson('/api/games/icons', [
            'name' => 'Dota 2', 'icon' => UploadedFile::fake()->image('a.png', 64, 64), 'source' => 'steam',
        ])->assertStatus(202)->json('submission_id');

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/games/icons', [
            'name' => 'Dota 2', 'icon' => UploadedFile::fake()->image('b.png', 64, 64), 'source' => 'exe',
        ])->assertJsonPath('pending', true)->assertJsonPath('submission_id', $first);

        $this->assertSame(1, GameIconSubmission::query()->count());
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $privilege = Privilege::query()->firstOrCreate(['name' => Privilege::ADMIN]);
        $admin->privileges()->attach($privilege->id);

        return $admin;
    }
}
