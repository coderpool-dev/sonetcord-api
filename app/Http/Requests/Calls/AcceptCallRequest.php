<?php

namespace App\Http\Requests\Calls;

/**
 * call_id — звонок, в который клиент хочет войти. Принимают по каналу, и без сверки клиент,
 * восстанавливавший старый звонок после перезагрузки, попадал в чужой новый звонок того же
 * канала (Viper, 2026-10-04), а потом не мог войти в его комнату.
 */
class AcceptCallRequest extends CallSessionRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'call_id' => ['nullable'],
            'client_build' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function callId(): ?string
    {
        $callId = $this->validated('call_id');

        return is_scalar($callId) && (string) $callId !== '' ? substr((string) $callId, 0, 64) : null;
    }
}
