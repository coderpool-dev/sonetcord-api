<?php

namespace Tests\Feature;

use App\Models\Conversations\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithCalls;
use Tests\TestCase;

class AttachmentControllerTest extends TestCase
{
    use InteractsWithCalls;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Event::fake();
    }

    public function test_general_api_traffic_does_not_consume_attachment_rate_limit(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        for ($i = 0; $i < 120; $i++) {
            RateLimiter::hit((string) $user->getAuthIdentifier());
        }

        $this->postJson('/api/messages/attachment', [
            'channels_id' => $channel->id,
            'file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
        ])->assertCreated();
    }

    public function test_member_can_upload_image_and_it_is_compressed_to_webp(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);

        Sanctum::actingAs($user);

        $this->postJson('/api/messages/attachment', [
            'channels_id' => $channel->id,
            'file' => UploadedFile::fake()->image('photo.jpg', 3000, 2000),
        ])->assertCreated();

        $message = Message::where('channels_id', $channel->id)->first();
        $this->assertSame('image', $message->type);

        $attachment = $message->meta['attachment'];
        $this->assertSame('image/webp', $attachment['mime']);          // пережато в WebP
        $this->assertLessThanOrEqual(1600, max($attachment['width'], $attachment['height'])); // ресайз
        $this->assertTrue($attachment['encrypted']);
        $this->assertSame((int) config('app.encryption_actual'), $attachment['key_id']);
        Storage::disk('local')->assertExists($attachment['disk_path']);
        $stored = Storage::disk('local')->get($attachment['disk_path']);
        $this->assertStringStartsWith('GCM1', $stored);
        $this->assertFalse(str_starts_with($stored, 'RIFF'));
    }

    public function test_unreadable_image_is_stored_as_file_instead_of_failing(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);

        Sanctum::actingAs($user);

        // Сигнатура PNG (finfo скажет image/png), но дальше мусор — GD прочитать не сможет.
        $broken = "\x89PNG\r\n\x1a\n".str_repeat("\x00garbage", 64);

        $this->postJson('/api/messages/attachment', [
            'channels_id' => $channel->id,
            'file' => UploadedFile::fake()->createWithContent('broken.png', $broken),
        ])->assertCreated();

        $attachment = Message::where('channels_id', $channel->id)->firstOrFail()->meta['attachment'];
        $this->assertSame('file', $attachment['kind']);
        $this->assertSame('image/png', $attachment['mime']);
        Storage::disk('local')->assertExists($attachment['disk_path']);
    }

    public function test_member_can_upload_a_document_as_file(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);

        Sanctum::actingAs($user);

        $this->postJson('/api/messages/attachment', [
            'channels_id' => $channel->id,
            'file' => UploadedFile::fake()->create('doc.pdf', 200, 'application/pdf'),
        ])->assertCreated();

        $message = Message::where('channels_id', $channel->id)->first();
        $this->assertSame('file', $message->type);
        $this->assertSame('doc.pdf', $message->meta['attachment']['name']);
    }

    public function test_caption_is_stored_encrypted(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);

        Sanctum::actingAs($user);

        $this->postJson('/api/messages/attachment', [
            'channels_id' => $channel->id,
            'caption' => 'смотри какое фото',
            'file' => UploadedFile::fake()->image('p.png', 100, 100),
        ])->assertCreated();

        $message = Message::where('channels_id', $channel->id)->first();
        $this->assertNotSame('смотри какое фото', $message->message); // зашифровано

        // А при чтении канала подпись расшифровывается обратно.
        $this->getJson("/api/messages/{$channel->id}/")
            ->assertOk()
            ->assertJsonFragment(['message' => 'смотри какое фото']);
    }

    public function test_non_member_cannot_upload(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();

        Sanctum::actingAs($user);

        $this->postJson('/api/messages/attachment', [
            'channels_id' => $channel->id,
            'file' => UploadedFile::fake()->image('x.jpg'),
        ])->assertForbidden();

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_rejects_too_large_and_disallowed_files(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);

        Sanctum::actingAs($user);

        // Исполняемый/скриптовый тип не в вайтлисте.
        $this->postJson('/api/messages/attachment', [
            'channels_id' => $channel->id,
            'file' => UploadedFile::fake()->create('evil.php', 10, 'application/x-php'),
        ])->assertStatus(422);

        // Слишком большой файл (> 25 МБ).
        $this->postJson('/api/messages/attachment', [
            'channels_id' => $channel->id,
            'file' => UploadedFile::fake()->create('big.pdf', 30000, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_messages_list_exposes_attachment_url_but_not_disk_path(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);

        Sanctum::actingAs($user);

        $this->postJson('/api/messages/attachment', [
            'channels_id' => $channel->id,
            'file' => UploadedFile::fake()->image('p.jpg', 100, 100),
        ])->assertCreated();

        $response = $this->getJson("/api/messages/{$channel->id}/")->assertOk();

        $messagePayload = $response->json('data.0');
        $this->assertArrayHasKey('attachment', $messagePayload);
        $this->assertStringContainsString('/api/attachments/', $messagePayload['attachment']['url']);
        $this->assertStringContainsString('signature=', $messagePayload['attachment']['url']);
        $this->assertStringContainsString('expires=', $messagePayload['attachment']['url']);
        $this->assertStringContainsString('user=', $messagePayload['attachment']['url']);
        // disk_path не должен утекать наружу.
        $this->assertStringNotContainsString('disk_path', json_encode($messagePayload));
    }

    public function test_signed_url_serves_the_file_and_unsigned_is_forbidden(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);

        Sanctum::actingAs($user);

        $this->postJson('/api/messages/attachment', [
            'channels_id' => $channel->id,
            'file' => UploadedFile::fake()->image('p.jpg', 100, 100),
        ])->assertCreated();

        $message = Message::where('channels_id', $channel->id)->first();
        $signedUrl = $this->getJson("/api/messages/{$channel->id}/")->json('data.0.attachment.url');

        // По подписанной ссылке файл расшифровывается и отдаётся как настоящий WebP.
        $response = $this->get($signedUrl)->assertOk();
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('RIFF', $response->streamedContent());

        // Без подписи — 403 (middleware 'signed').
        $this->get("/api/attachments/{$message->id}")->assertForbidden();
    }

    public function test_legacy_unencrypted_attachment_is_still_served(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        $path = "attachments/{$channel->id}/legacy.txt";
        Storage::disk('local')->put($path, 'legacy contents');

        $message = Message::create([
            'user_id' => $user->id,
            'channels_id' => $channel->id,
            'type' => 'file',
            'message' => '',
            'key_id' => (int) config('app.encryption_actual'),
            'meta' => [
                'attachment' => [
                    'disk_path' => $path,
                    'name' => 'legacy.txt',
                    'mime' => 'text/plain',
                    'size' => 15,
                    'kind' => 'file',
                    'width' => null,
                    'height' => null,
                ],
            ],
        ]);

        $signedUrl = $this->getJson("/api/messages/{$channel->id}/")->json('data.0.attachment.url');
        $response = $this->get($signedUrl)->assertOk();

        $this->assertSame('legacy contents', $response->streamedContent());
        $this->assertStringContainsString('legacy.txt', $response->headers->get('Content-Disposition'));
        $this->assertSame($message->id, Message::first()->id);
    }

    public function test_encrypted_media_supports_range_requests_for_inline_players(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        $this->postJson('/api/messages/attachment', [
            'channels_id' => $channel->id,
            'file' => UploadedFile::fake()->createWithContent('track.mp3', '0123456789abcdef'),
        ])->assertCreated();

        $signedUrl = $this->getJson("/api/messages/{$channel->id}/")->json('data.0.attachment.url');
        $response = $this->withHeader('Range', 'bytes=4-9')->get($signedUrl);

        $response->assertStatus(206);
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame('bytes 4-9/16', $response->headers->get('Content-Range'));
        $this->assertSame('inline; filename=track.mp3', $response->headers->get('Content-Disposition'));
        $this->assertSame('456789', $response->streamedContent());
    }

    public function test_left_member_cannot_download_with_old_signed_url(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        $this->postJson('/api/messages/attachment', [
            'channels_id' => $channel->id,
            'file' => UploadedFile::fake()->image('p.jpg', 100, 100),
        ])->assertCreated();

        $signedUrl = $this->getJson("/api/messages/{$channel->id}/")->json('data.0.attachment.url');
        $this->get($signedUrl)->assertOk();

        $this->deleteJson("/api/channels/{$channel->id}")->assertOk();
        $this->get($signedUrl)->assertForbidden();
    }

    public function test_signed_attachment_url_expires(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        $this->postJson('/api/messages/attachment', [
            'channels_id' => $channel->id,
            'file' => UploadedFile::fake()->image('p.jpg', 100, 100),
        ])->assertCreated();

        $signedUrl = $this->getJson("/api/messages/{$channel->id}/")->json('data.0.attachment.url');

        // Срок округляется до часа вверх, поэтому ссылка живёт от 24 до 25 часов.
        $this->travel(26)->hours();
        $this->get($signedUrl)->assertForbidden();
    }

    /** Иначе после каждого нового сообщения браузер заново скачивает все картинки чата. */
    public function test_attachment_url_is_stable_within_an_hour(): void
    {
        $this->travelTo(now()->startOfHour()->addMinutes(5));

        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        $this->postJson('/api/messages/attachment', [
            'channels_id' => $channel->id,
            'file' => UploadedFile::fake()->image('p.jpg', 100, 100),
        ])->assertCreated();

        $first = $this->getJson("/api/messages/{$channel->id}/")->json('data.0.attachment.url');

        $this->travel(20)->minutes();
        $second = $this->getJson("/api/messages/{$channel->id}/")->json('data.0.attachment.url');

        $this->assertSame($first, $second);
    }
}
