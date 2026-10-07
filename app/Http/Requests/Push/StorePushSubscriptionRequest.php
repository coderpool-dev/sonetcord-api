<?php

namespace App\Http\Requests\Push;

use App\Support\PushEndpoint;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Подписка браузера на Web Push — то, что отдаёт PushSubscription.toJSON(). */
class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'url', 'max:2000', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! PushEndpoint::isAllowed($value)) {
                    $fail('Недопустимый адрес push-сервиса');
                }
            }],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ];
    }
}
