<?php

namespace App\Console\Commands;

use App\Services\Conversations\CallPresenceService;
use App\Services\Conversations\CallService;
use Illuminate\Console\Command;

class ReapStaleCalls extends Command
{
    protected $signature = 'calls:reap-stale';

    protected $description = 'Removes stale call sessions and ends calls that stayed understaffed';

    public function handle(CallService $callService, CallPresenceService $presence): int
    {
        // Сессии уже завершённых звонков: обычная уборка смотрит только активные звонки.
        $orphaned = $presence->pruneOrphanedSessions();
        $ended = $callService->reapStaleCalls();

        if ($orphaned > 0) {
            $this->info("Removed {$orphaned} session(s) of ended calls.");
        }
        if ($ended > 0) {
            $this->info("Finalized {$ended} stale call(s).");
        }

        return self::SUCCESS;
    }
}
