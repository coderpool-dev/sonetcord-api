<?php

namespace App\Http\Controllers\API;

use App\Data\GeoLocationData;
use App\Data\NetworkLatencySample;
use App\Http\Controllers\Controller;
use App\Services\NetworkLatencyService;
use App\Services\Presence\GeoIpService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NetworkLatencyController extends Controller
{
    public function probe()
    {
        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, GeoIpService $geo, NetworkLatencyService $service)
    {
        abort_if(strlen($request->getContent()) > 1024, 413);
        $sample = $request->validate([
            'metric' => ['required', Rule::in(['api', 'voice'])], 'ok' => ['required', 'boolean'],
            'rtt_ms' => ['nullable', 'required_if:ok,true', 'numeric', 'min:0', 'max:10000'],
        ]);
        // Location is resolved on the reporting request, never on the timed probe.
        $location = $geo->lookupIp($request->ip());
        $service->record((int) $request->user()->id, NetworkLatencySample::fromArray($sample), GeoLocationData::fromArray($location));

        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }
}
