<?php

namespace App\Http\Controllers\API;

use App\Data\ClientDiagnosticsBatch;
use App\Http\Controllers\Controller;
use App\Services\ClientDiagnosticsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ClientDiagnosticsController extends Controller
{
    public function __invoke(Request $request, ClientDiagnosticsService $diagnostics): Response
    {
        abort_if(strlen($request->getContent()) > 32_000, 413);
        $data = $request->validate([
            'session_id' => ['required', 'uuid'], 'batch_id' => ['required', 'uuid'],
            'platform' => ['required', Rule::in(['web', 'desktop', 'mobile'])],
            'build' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:+-]+$/'],
            'events' => ['required', 'array', 'min:1', 'max:20'],
            'events.*' => ['required', 'array:event,level,at,page,online,visibility,repeat,data'],
            'events.*.event' => ['required', Rule::in(ClientDiagnosticsService::EVENTS)],
            'events.*.level' => ['required', Rule::in(['info', 'warning', 'error'])],
            'events.*.at' => ['required', 'date'], 'events.*.page' => ['required', 'string', 'max:250'],
            'events.*.online' => ['required', 'boolean'],
            'events.*.visibility' => ['required', Rule::in(['visible', 'hidden', 'prerender'])],
            'events.*.repeat' => ['required', 'integer', 'min:1', 'max:10000'],
            'events.*.data' => ['present', 'array:room_id,attempt_id,state,reason,media,error_name,error_summary,stack_frames,endpoint,method,status,duration_ms,cancelled,audio_enabled'],
            'events.*.data.room_id' => ['sometimes', 'string', 'max:80', 'regex:/^(call-[a-f0-9-]{36}|server-channel-\d{1,20})$/i'],
            'events.*.data.attempt_id' => ['sometimes', 'uuid'],
            'events.*.data.state' => ['sometimes', Rule::in(['disconnected', 'connecting', 'connected', 'reconnecting', 'signalReconnecting'])],
            'events.*.data.reason' => ['sometimes', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/'],
            'events.*.data.media' => ['sometimes', Rule::in(['mic', 'screen', 'screenAudio', 'camera', 'audio', 'video'])],
            'events.*.data.error_name' => ['sometimes', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_.]*$/'],
            'events.*.data.error_summary' => ['sometimes', Rule::in(['unknown', 'canceled', 'timeout', 'permission_denied', 'not_found', 'device_busy', 'network', 'authentication', 'runtime', 'browser_autoplay'])],
            'events.*.data.stack_frames' => ['sometimes', 'array', 'max:5'],
            'events.*.data.stack_frames.*' => ['required', 'string', 'max:240', 'regex:/^\/_next\/static\/[A-Za-z0-9_\/.%-]+:\d+:\d+$/'],
            'events.*.data.endpoint' => ['sometimes', 'string', 'max:250'],
            'events.*.data.method' => ['sometimes', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])],
            'events.*.data.status' => ['sometimes', 'regex:/^(\d{3}|FETCH_ERROR|TIMEOUT_ERROR|PARSING_ERROR|CUSTOM_ERROR)$/'],
            'events.*.data.duration_ms' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
            'events.*.data.cancelled' => ['sometimes', 'boolean'],
            'events.*.data.audio_enabled' => ['sometimes', 'boolean'],
        ]);
        $diagnostics->store($request->user(), ClientDiagnosticsBatch::fromArray($data), $request->userAgent() ?? '');

        return response()->noContent();
    }
}
