<?php

namespace Tests\Feature;

use App\Models\Conversations\Attachment;
use App\Models\Conversations\Message;
use App\Models\Uploads\UploadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithCalls;
use Tests\TestCase;

class UploadFlowTest extends TestCase
{
    use InteractsWithCalls;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Event::fake();
    }

    /** Докачиваемая загрузка большого (нешифрованного) файла собирается из чанков. */
    public function test_chunked_upload_assembles_large_unencrypted_file(): void
    {
        // Порог шифрования мал → тестовый файл считается «большим» и хранится как есть.
        config(['uploads.encrypt_max_bytes' => 4]);

        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        $content = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'; // 36 байт
        $uploadId = $this->initUpload($channel->id, 'movie.bin', strlen($content), 'application/octet-stream');

        // Шлём двумя кусками.
        $this->sendChunk($uploadId, 0, substr($content, 0, 20));
        $this->sendChunk($uploadId, 20, substr($content, 20));

        $this->postJson("/api/uploads/{$uploadId}/complete", [])->assertCreated();

        $message = Message::where('channels_id', $channel->id)->first();
        $this->assertSame('file', $message->type);
        $attachment = $message->meta['attachment'];
        $this->assertFalse($attachment['encrypted']);
        $this->assertSame(36, $attachment['size']);
        Storage::disk('local')->assertExists($attachment['disk_path']);
        // Хранится как есть (без GCM1-префикса шифрования).
        $this->assertSame($content, Storage::disk('local')->get($attachment['disk_path']));

        // Строка учёта создана и привязана к сообщению.
        $row = Attachment::where('message_id', $message->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(36, (int) $row->size);
        $this->assertFalse((bool) $row->encrypted);

        // Сохраняем результат для идемпотентного повтора complete.
        $this->assertDatabaseHas('upload_sessions', ['id' => $uploadId, 'message_id' => $message->id]);
    }

    /** Большой файл отдаётся потоком с диска с поддержкой Range (перемотка). */
    public function test_large_file_is_streamed_with_range(): void
    {
        config(['uploads.encrypt_max_bytes' => 4]);

        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        $content = '0123456789abcdef';
        $uploadId = $this->initUpload($channel->id, 'clip.bin', strlen($content), 'application/octet-stream');
        $this->sendChunk($uploadId, 0, $content);
        $this->postJson("/api/uploads/{$uploadId}/complete", [])->assertCreated();

        $signedUrl = $this->getJson("/api/messages/{$channel->id}/")->json('data.0.attachment.url');

        $response = $this->withHeader('Range', 'bytes=4-9')->get($signedUrl);
        $response->assertStatus(206);
        $this->assertSame('bytes 4-9/16', $response->headers->get('Content-Range'));
        $this->assertSame('456789', $response->streamedContent());
    }

    /** На проде большой файл отдаёт nginx: PHP только проверяет доступ и отвечает X-Accel-Redirect. */
    public function test_large_file_is_handed_to_nginx_when_prefix_is_configured(): void
    {
        config(['uploads.encrypt_max_bytes' => 4, 'filesystems.private_x_accel_prefix' => '/_private/']);

        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        $content = '0123456789abcdef';
        $uploadId = $this->initUpload($channel->id, 'clip.bin', strlen($content), 'application/octet-stream');
        $this->sendChunk($uploadId, 0, $content);
        $this->postJson("/api/uploads/{$uploadId}/complete", [])->assertCreated();

        $diskPath = Message::where('channels_id', $channel->id)->first()->meta['attachment']['disk_path'];
        $signedUrl = $this->getJson("/api/messages/{$channel->id}/")->json('data.0.attachment.url');

        $response = $this->get($signedUrl)->assertOk();
        $this->assertSame('/_private/'.$diskPath, $response->headers->get('X-Accel-Redirect'));
        $this->assertStringContainsString('clip.bin', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('', $response->getContent());
    }

    /** Полный круг (большой нешифрованный): скачанные байты точно совпадают с исходными. */
    public function test_chunked_round_trip_preserves_exact_bytes(): void
    {
        config(['uploads.encrypt_max_bytes' => 4]); // хранится без шифрования

        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        // «Злой» бинарник: все байты 0–255, включая нули и старшие — ловит порчу кодировкой.
        $content = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $content .= str_repeat(chr($byte), 500);
        }
        $this->assertSame(128000, strlen($content));

        $uploadId = $this->initUpload($channel->id, 'blob.bin', strlen($content), 'application/octet-stream');
        // Шлём кусками по 40000 байт (4 чанка) — проверяем сборку из нескольких кусков.
        for ($off = 0; $off < strlen($content); $off += 40000) {
            $this->sendChunk($uploadId, $off, substr($content, $off, 40000));
        }
        $this->postJson("/api/uploads/{$uploadId}/complete", [])->assertCreated();

        $signedUrl = $this->getJson("/api/messages/{$channel->id}/")->json('data.0.attachment.url');
        $response = $this->get($signedUrl)->assertOk();

        $this->assertSame(strlen($content), (int) $response->headers->get('Content-Length'));
        $this->assertSame($content, $response->streamedContent()); // байт-в-байт
    }

    /** Полный круг (мелкий шифрованный): расшифрованные байты точно совпадают с исходными. */
    public function test_encrypted_round_trip_preserves_exact_bytes(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        $content = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $content .= chr($byte);
        }
        $content = str_repeat($content, 10); // 2560 байт, заведомо < порога шифрования

        $uploadId = $this->initUpload($channel->id, 'small.bin', strlen($content), 'application/octet-stream');
        $this->sendChunk($uploadId, 0, $content);
        $this->postJson("/api/uploads/{$uploadId}/complete", [])->assertCreated();

        $message = Message::where('channels_id', $channel->id)->first();
        $this->assertTrue($message->meta['attachment']['encrypted']); // шифруется

        $signedUrl = $this->getJson("/api/messages/{$channel->id}/")->json('data.0.attachment.url');
        $response = $this->get($signedUrl)->assertOk();

        $this->assertSame($content, $response->streamedContent());
    }

    /** Если собранный файл короче заявленного — complete отклоняет (битый не станет сообщением). */
    public function test_complete_rejects_truncated_assembly(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        $uploadId = $this->initUpload($channel->id, 'broken.bin', 100, 'application/octet-stream');
        // На диск попало только 60 байт...
        $this->sendChunk($uploadId, 0, str_repeat('x', 60));
        // ...но счётчик принятых байт «доехал» до 100 (симулируем потерю при записи).
        UploadSession::whereKey($uploadId)->update(['received_size' => 100]);

        $this->postJson("/api/uploads/{$uploadId}/complete", [])->assertStatus(422);
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('attachments', 0);
    }

    /** Превышение персональной квоты блокирует начало загрузки (413). */
    public function test_quota_blocks_init(): void
    {
        config(['uploads.user_quota_bytes' => 100]);

        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        // Уже занято 90 из 100 байт.
        Attachment::create([
            'user_id' => $user->id, 'channel_id' => $channel->id,
            'disk_path' => 'attachments/x/old.bin', 'name' => 'old.bin',
            'mime' => 'application/octet-stream', 'size' => 90, 'kind' => 'file',
            'encrypted' => false, 'last_accessed_at' => now(),
        ]);

        $this->postJson('/api/uploads', [
            'channels_id' => $channel->id,
            'filename' => 'new.bin',
            'size' => 50,
            'mime' => 'application/octet-stream',
        ])->assertStatus(413);
    }

    /** Файл больше глобального лимита отклоняется на init. */
    public function test_oversize_blocks_init(): void
    {
        config(['uploads.max_bytes' => 1000]);

        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        $this->postJson('/api/uploads', [
            'channels_id' => $channel->id,
            'filename' => 'huge.bin',
            'size' => 5000,
            'mime' => 'application/octet-stream',
        ])->assertStatus(413);
    }

    public function test_non_member_cannot_init_upload(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        Sanctum::actingAs($user);

        $this->postJson('/api/uploads', [
            'channels_id' => $channel->id,
            'filename' => 'f.bin',
            'size' => 10,
        ])->assertForbidden();
    }

    /** Ретеншн удаляет сообщения и их файлы старше срока, не трогая свежие. */
    public function test_retention_prunes_old_messages_and_files(): void
    {
        config(['uploads.retention_days' => 7]);

        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);

        $oldPath = "attachments/{$channel->id}/old.bin";
        Storage::disk('local')->put($oldPath, 'old data');

        $old = Message::create([
            'user_id' => $user->id, 'channels_id' => $channel->id, 'type' => 'file',
            'message' => '', 'key_id' => (int) config('app.encryption_actual'),
            'meta' => ['attachment' => ['disk_path' => $oldPath, 'name' => 'old.bin', 'mime' => 'application/octet-stream', 'size' => 8, 'kind' => 'file']],
        ]);
        $old->created_at = now()->subDays(30);
        $old->save();
        Attachment::create([
            'message_id' => $old->id, 'user_id' => $user->id, 'channel_id' => $channel->id,
            'disk_path' => $oldPath, 'name' => 'old.bin', 'mime' => 'application/octet-stream',
            'size' => 8, 'kind' => 'file', 'encrypted' => false, 'last_accessed_at' => now()->subDays(30),
        ]);

        $recent = Message::create([
            'user_id' => $user->id, 'channels_id' => $channel->id, 'type' => 'text',
            'message' => 'свежее', 'key_id' => (int) config('app.encryption_actual'),
        ]);

        $this->artisan('attachments:prune')->assertExitCode(0);

        $this->assertNull(Message::find($old->id));
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertNotNull(Message::find($recent->id));
    }

    /** Две загрузки подряд пишут каждая в свой временный файл и не портят друг друга. */
    public function test_parallel_uploads_do_not_share_temp_file(): void
    {
        config(['uploads.encrypt_max_bytes' => 4]);

        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        $first = $this->initUpload($channel->id, 'first.bin', 10, 'application/octet-stream');
        $second = $this->initUpload($channel->id, 'second.bin', 10, 'application/octet-stream');

        $this->sendChunk($first, 0, str_repeat('A', 10));
        $this->sendChunk($second, 0, str_repeat('B', 10));

        $this->postJson("/api/uploads/{$first}/complete", [])->assertCreated();
        $this->postJson("/api/uploads/{$second}/complete", [])->assertCreated();

        $stored = Message::where('channels_id', $channel->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Message $message) => Storage::disk('local')->get($message->meta['attachment']['disk_path']))
            ->all();

        $this->assertSame([str_repeat('A', 10), str_repeat('B', 10)], $stored);
    }

    /** Чужая сессия загрузки не видна: 404, чтобы не подтверждать её существование. */
    public function test_foreign_upload_session_is_not_found(): void
    {
        $owner = $this->makeUser();
        $stranger = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $owner);

        Sanctum::actingAs($owner);
        $uploadId = $this->initUpload($channel->id, 'secret.bin', 10, 'application/octet-stream');

        Sanctum::actingAs($stranger);
        $this->getJson("/api/uploads/{$uploadId}")->assertNotFound();
        $this->postJson("/api/uploads/{$uploadId}/complete", [])->assertNotFound();
    }

    /** Пока один запрос дописывает кусок, повтор с тем же смещением не пишет данные второй раз. */
    public function test_chunk_is_rejected_while_session_is_locked(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        Sanctum::actingAs($user);

        $uploadId = $this->initUpload($channel->id, 'race.bin', 10, 'application/octet-stream');

        $lock = Cache::lock("upload-session:{$uploadId}", 10);
        $lock->get();

        $this->call(
            'PATCH',
            "/api/uploads/{$uploadId}",
            [], [], [],
            $this->transformHeadersToServerVars(['Upload-Offset' => '0']),
            str_repeat('x', 10)
        )->assertStatus(409)->assertJsonPath('received', 0);

        $lock->release();

        $this->sendChunk($uploadId, 0, str_repeat('x', 10));
    }

    private function initUpload(int $channelId, string $filename, int $size, string $mime): string
    {
        return $this->postJson('/api/uploads', [
            'channels_id' => $channelId,
            'filename' => $filename,
            'size' => $size,
            'mime' => $mime,
        ])->assertCreated()->json('upload_id');
    }

    private function sendChunk(string $uploadId, int $offset, string $chunk): void
    {
        $this->call(
            'PATCH',
            "/api/uploads/{$uploadId}",
            [], [], [],
            $this->transformHeadersToServerVars(['Upload-Offset' => (string) $offset]),
            $chunk
        )->assertOk();
    }
}
