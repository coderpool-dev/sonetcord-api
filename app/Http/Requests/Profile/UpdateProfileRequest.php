<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,'.$userId,
            'date' => 'sometimes|date|after_or_equal:1900-01-01|before_or_equal:today',
            'current_password' => 'sometimes|required_with:new_password|string',
            'new_password' => 'sometimes|required_with:current_password|string|min:8|confirmed',
            'avatar' => 'sometimes|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            // Баннер профиля (картинка) — баннер шире, лимит чуть выше аватара.
            'banner' => 'sometimes|image|mimes:jpeg,png,jpg,gif,webp|max:8192',
            'banner_color' => 'sometimes|nullable|string|regex:/^#([0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/',
            // Флаги-«сбросить»: убрать баннер / очистить кастом-статус. Принимаем "1"/true.
            'remove_banner' => 'sometimes|boolean',
            'clear_status' => 'sometimes|boolean',
            'clear_game_status' => 'sometimes|boolean',
            // Статусы.
            'presence' => 'sometimes|string|in:online,idle,dnd,invisible',
            'status_emoji' => 'sometimes|nullable|string|max:32',
            'status_text' => 'sometimes|nullable|string|max:128',
            'game_status_text' => 'sometimes|nullable|string|max:128',
        ];
    }

    public function messages(): array
    {
        return [
            'name.string' => 'Имя должно быть строкой',
            'name.max' => 'Имя не должно превышать 255 символов',
            'email.string' => 'Email должен быть строкой',
            'email.email' => 'Неверный формат email',
            'email.max' => 'Email не должен превышать 255 символов',
            'email.unique' => 'Этот email уже используется другим пользователем',
            'date.date' => 'Неверный формат даты',
            'date.after_or_equal' => 'Неверная дата рождения',
            'date.before_or_equal' => 'Неверная дата рождения',
            'current_password.required_with' => 'Текущий пароль обязателен при изменении пароля',
            'current_password.string' => 'Текущий пароль должен быть строкой',
            'new_password.required_with' => 'Новый пароль обязателен при изменении пароля',
            'new_password.string' => 'Новый пароль должен быть строкой',
            'new_password.min' => 'Новый пароль должен содержать минимум 8 символов',
            'new_password.confirmed' => 'Подтверждение пароля не совпадает',
            'avatar.image' => 'Файл должен быть изображением',
            'avatar.mimes' => 'Изображение должно быть в формате: jpeg, png, jpg, gif, webp',
            'avatar.max' => 'Размер изображения не должен превышать 5MB',
            'banner.image' => 'Баннер должен быть изображением',
            'banner.mimes' => 'Баннер должен быть в формате: jpeg, png, jpg, gif, webp',
            'banner.max' => 'Размер баннера не должен превышать 8MB',
            'banner_color.regex' => 'Цвет должен быть в формате HEX (#RRGGBB)',
            'presence.in' => 'Недопустимый статус присутствия',
            'status_text.max' => 'Статус не должен превышать 128 символов',
            'game_status_text.max' => 'Игровой статус не должен превышать 128 символов',
        ];
    }
}
