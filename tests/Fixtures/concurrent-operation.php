<?php

use App\Models\Conversations\Message;
use App\Models\User;
use App\Services\Conversations\CallService;
use App\Services\Conversations\MessageService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

// Separate connections are necessary to exercise InnoDB row locks.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || config('database.default') !== 'mysql') {
    throw new RuntimeException('Concurrency worker requires a MySQL test database');
}
Event::fake();
config(['services.webpush.public_key' => null]);
[$script, $operation, $userId, $targetId, $gate] = $argv;
$user = User::findOrFail($userId);

// Widen the read/write race; without parent row locks both workers read the old state.
DB::listen(function ($query) use ($operation): void {
    if (str_starts_with(strtolower($query->sql), 'select')
        && str_contains($query->sql, $operation === 'call' ? '`calls`' : '`message_reactions`')) {
        usleep(300_000);
    }
});
echo "READY\n";
flush();
$deadline = microtime(true) + 15;
while (! file_exists($gate)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency test start timed out');
    }
    usleep(10_000);
}

if ($operation === 'call') {
    $start = app(CallService::class)->create($user, (int) $targetId);
    echo json_encode(['created' => $start->created], JSON_THROW_ON_ERROR);
} else {
    $message = Message::findOrFail($targetId);
    echo json_encode(['added' => app(MessageService::class)->toggleReaction($user, $message, 'test')], JSON_THROW_ON_ERROR);
}
