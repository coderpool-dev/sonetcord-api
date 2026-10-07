<?php

namespace App\Http\Requests\Calls;

class CallHeartbeatRequest extends CallSessionRequest
{
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'string'],
            'screen_sharing' => ['sometimes', 'boolean'],
            'call_id' => ['sometimes', 'string'],
        ];
    }

    public function sessionId(): string
    {
        return (string) parent::sessionId();
    }

    public function callId(): ?string
    {
        $callId = $this->input('call_id');

        return is_string($callId) && $callId !== '' ? $callId : null;
    }
}
