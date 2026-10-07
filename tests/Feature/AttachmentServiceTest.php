<?php

namespace Tests\Feature;

use App\Services\Conversations\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithCalls;
use Tests\TestCase;

class AttachmentServiceTest extends TestCase
{
    use InteractsWithCalls;
    use RefreshDatabase;

    public function test_small_gif_from_chunked_upload_keeps_its_dimensions(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        $channel = $this->makeChannel();

        // Раньше временный файл удалялся до чтения размеров, и у GIF ширина и высота были null.
        $tmpPath = tempnam(sys_get_temp_dir(), 'upload');
        imagegif(imagecreatetruecolor(40, 30), $tmpPath);

        $stored = app(AttachmentService::class)->finalizeFromTemp($tmpPath, $user->id, $channel->id, 'party.gif', 'image/gif');

        $this->assertSame('image', $stored->kind);
        $this->assertSame([40, 30], [$stored->width, $stored->height]);
        $this->assertTrue($stored->encrypted);
        $this->assertFileDoesNotExist($tmpPath);
        Storage::disk('local')->assertExists($stored->diskPath);
    }
}
