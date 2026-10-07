<?php

namespace Tests\Feature;

use App\Models\Conversations\Message;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithCalls;
use Tests\TestCase;

class ConcurrentConversationWritesTest extends TestCase
{
    use DatabaseMigrations, InteractsWithCalls;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Concurrent row locks are checked in the MySQL CI job.');
        }
    }

    public function test_concurrent_creation_produces_only_one_active_call(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        $results = $this->runConcurrent('call', $user->id, $channel->id);
        $this->assertEqualsCanonicalizing([true, false], array_column($results, 'created'));
        $this->assertDatabaseCount('calls', 1);
    }

    public function test_two_concurrent_toggles_add_then_remove_without_unique_key_error(): void
    {
        $user = $this->makeUser();
        $channel = $this->makeChannel();
        $this->addMember($channel, $user);
        $message = Message::create(['user_id' => $user->id, 'channels_id' => $channel->id, 'message' => '', 'key_id' => 1]);
        $results = $this->runConcurrent('reaction', $user->id, $message->id);
        $this->assertEqualsCanonicalizing([true, false], array_column($results, 'added'));
        $this->assertDatabaseCount('message_reactions', 0);
    }

    private function runConcurrent(string $operation, int $userId, int $targetId): array
    {
        $gate = tempnam(sys_get_temp_dir(), 'conversation-race-');
        $this->assertNotFalse($gate);
        unlink($gate);
        $database = config('database.connections.mysql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => $database['database'], 'DB_USERNAME' => $database['username'],
            'DB_PASSWORD' => $database['password'], 'BROADCAST_CONNECTION' => 'null',
            'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'array',
            'ENCRYPTION_KEY_1' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ];
        $processes = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-operation.php'),
                    $operation, (string) $userId, (string) $targetId, $gate], base_path(), $environment);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (! collect($processes)->every(fn (Process $process) => str_contains($process->getOutput(), 'READY'))) {
                $this->assertTrue(microtime(true) < $deadline, 'Workers did not reach the start barrier');
                foreach ($processes as $process) {
                    $this->assertTrue($process->isRunning(), $process->getErrorOutput());
                }
                usleep(10_000);
            }
            touch($gate);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode(substr($process->getOutput(), strlen("READY\n")), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                $process->stop(1);
            }
            if (file_exists($gate)) {
                unlink($gate);
            }
        }
    }
}
