<?php

namespace App\Services;

use App\Data\ClientDiagnosticsBatch;
use App\Data\ClientLogFilters;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;

class ClientDiagnosticsService
{
    public const EVENTS = [
        'js_error', 'unhandled_rejection', 'api_error', 'network_change',
        'livekit_connect_start', 'livekit_connect_success', 'livekit_connect_error', 'livekit_connect_cancel',
        'livekit_disconnected', 'livekit_reconnecting', 'livekit_reconnected', 'livekit_connection_state',
        'livekit_media_error', 'livekit_audio_playback', 'livekit_track_subscribed', 'livekit_track_unsubscribed',
        'livekit_track_published', 'livekit_subscription_error', 'microphone_error', 'camera_error', 'screen_error',
    ];

    public function store(User $user, ClientDiagnosticsBatch $batch, string $agent): void
    {
        $key = 'client_diagnostics:'.$user->id.':'.$batch->batchId;
        if (! Cache::add($key, true, 86400)) {
            return;
        }
        try {
            $logger = Log::build([
                'driver' => 'daily', 'path' => storage_path('logs/client.log'), 'level' => 'info',
                'days' => 7, 'permission' => 0640, 'locking' => true, 'formatter' => JsonFormatter::class,
            ]);
            preg_match('/(Edg|OPR|Firefox|Chrome|Version)\/([0-9.]+)/', $agent, $browser);
            $browserName = match ($browser[1] ?? '') {
                'Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Version' => 'Safari', default => 'Other',
            };
            foreach ($batch->events as $entry) {
                $event = $entry->toArray();
                $event['page'] = $this->safePath($event['page']);
                if (isset($event['data']['endpoint'])) {
                    $event['data']['endpoint'] = $this->safePath($event['data']['endpoint']);
                }
                $logger->info('client_event', [
                    'schema' => 1, 'received_at' => now()->toIso8601String(),
                    'user_id' => $user->id, 'login' => $user->login,
                    'session_id' => $batch->sessionId, 'batch_id' => $batch->batchId,
                    'platform' => $batch->platform, 'build' => $batch->build,
                    'browser' => $browserName, 'browser_version' => $browser[2] ?? '',
                    ...$event,
                ]);
            }
        } catch (\Throwable $e) {
            Cache::forget($key);
            throw $e;
        }
    }

    public function recent(ClientLogFilters $filters): array
    {
        $start = CarbonImmutable::parse($filters->date ?? now('Europe/Moscow')->toDateString(), 'Europe/Moscow')->startOfDay();
        $end = $start->addDay();
        $files = array_unique([$start->utc()->toDateString(), $end->subSecond()->utc()->toDateString()]);
        $entries = [];
        $truncated = false;
        foreach ($files as $date) {
            $path = storage_path('logs/client-'.$date.'.log');
            if (! is_readable($path)) {
                continue;
            }
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                continue;
            }
            try {
                $size = fstat($stream)['size'];
                if ($size > 2_000_000) {
                    fseek($stream, -2_000_000, SEEK_END);
                    fgets($stream);
                    $truncated = true;
                }
                while (($line = fgets($stream)) !== false) {
                    $record = json_decode($line, true);
                    $event = $record['context'] ?? null;
                    if (! is_array($event) || ($event['schema'] ?? null) !== 1) {
                        continue;
                    }
                    $stamp = strtotime($event['received_at']);
                    if ($stamp < $start->timestamp || $stamp >= $end->timestamp) {
                        continue;
                    }
                    foreach (['user_id' => $filters->userId, 'session_id' => $filters->sessionId, 'level' => $filters->level, 'event' => $filters->event] as $key => $value) {
                        if ($value !== null && (string) $value !== (string) ($event[$key] ?? '')) {
                            continue 2;
                        }
                    }
                    $entries[] = $event;
                    if (count($entries) > 1000) {
                        array_shift($entries);
                        $truncated = true;
                    }
                }
            } finally {
                fclose($stream);
            }
        }
        usort($entries, fn ($a, $b) => strcmp($b['received_at'], $a['received_at']));

        return ['entries' => array_slice($entries, 0, 200), 'date' => $start->toDateString(),
            'timezone' => 'Europe/Moscow', 'truncated' => $truncated || count($entries) > 200, 'retention_days' => 7];
    }

    public function safePath(string $value): string
    {
        $path = parse_url($value, PHP_URL_PATH) ?: '/';
        $known = explode(' ', 'api admin auth profile login register calls active accept leave heartbeat livekit webrtc signal channels messages friends storage attachments presence ping integrations yandex-music sync activity game-ping users support system-monitor client-logs client-diagnostics dashboard contacts settings forgot-password reset-password personal tech downloads windows i invite report feedback read typing members sessions logout-all games icons servers');
        $segments = array_slice(array_values(array_filter(explode('/', $path), fn ($part) => $part !== '')), 0, 10);

        return '/'.implode('/', array_map(fn ($part) => in_array($part, $known, true) ? $part : ':id', $segments));
    }
}
