<?php

namespace App\Http\Requests\Auth;

use App\Services\Account\UnverifiedUserService;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'login' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[^@]+$/'],
            'password' => 'required|string|min:8|confirmed',
            'date' => 'sometimes|date|after_or_equal:1900-01-01|before_or_equal:today',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'login' => trim((string) $this->input('login')),
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $email = strtolower(trim((string) $this->input('email')));
            $login = trim((string) $this->input('login'));

            $unverifiedUsers = app(UnverifiedUserService::class);
            $unverifiedUsers->pruneConflictsFor($email, $login);

            foreach ($unverifiedUsers->registrationConflicts($email, $login) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Имя обязательно для заполнения',
            'name.string' => 'Имя должно быть строкой',
            'name.max' => 'Имя не должно превышать 255 символов',
            'email.required' => 'Email обязателен для заполнения',
            'email.string' => 'Email должен быть строкой',
            'email.email' => 'Неверный формат email',
            'email.max' => 'Email не должен превышать 255 символов',
            'email.unique' => 'Этот email уже зарегистрирован',
            'login.required' => 'Логин обязателен для заполнения',
            'login.string' => 'Логин должен быть строкой',
            'login.max' => 'Логин не должен превышать 32 символов',
            'login.min' => 'Логин должен содержать минимум 3 символа',
            'login.regex' => 'Символ @ в логине запрещён',
            'login.unique' => 'Этот логин уже занят',
            'password.required' => 'Пароль обязателен для заполнения',
            'password.string' => 'Пароль должен быть строкой',
            'password.min' => 'Пароль должен содержать минимум 8 символов',
            'password.confirmed' => 'Пароли не совпадают',
            'date.date' => 'Неверный формат даты',
            'date.after_or_equal' => 'Неверная дата рождения',
            'date.before_or_equal' => 'Неверная дата рождения',
        ];
    }
}
