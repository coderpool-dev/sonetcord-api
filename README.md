# SonetCord API

SonetCord — бэкенд для голосового мессенджера в духе Discord. Проект сделан как полноценный продукт: с регистрацией, личными чатами, серверами, ролями, правами, голосовыми каналами, звонками, демонстрацией экрана, загрузкой больших файлов, поддержкой пользователей и админкой.

Проект показывает работу с реальными продуктовыми сценариями: несколько устройств у одного пользователя, WebSocket-события, модерация голосовых комнат, resumable uploads, push-уведомления, демо-аккаунты, разграничение прав и автоматическая уборка устаревших данных.

- Продакшен: **https://sonetcord.ru** — на главной есть демо-вход без регистрации
- Архитектура и технические решения: **https://sonetcord.ru/tech**
- Фронтенд на Next.js и desktop-клиент на Electron лежат в отдельных репозиториях

## Скриншот продукта

![Демо-интерфейс SonetCord](docs/screenshots/app-demo.webp)

## Что показывает этот репозиторий

Для работодателя здесь важнее всего не количество файлов, а подход к разработке:

- код разделён по слоям: роуты, Form Request, контроллеры, политики, сервисы и API Resources;
- бизнес-логика вынесена из контроллеров в сервисы;
- права доступа проверяются на сервере через policies и отдельные сервисы разрешений;
- ошибки API возвращаются в одном формате и не раскрывают лишние детали;
- сложные сценарии покрыты feature- и unit-тестами;
- есть статический анализ и автоматическая проверка стиля;
- проект уже запускался в продакшене, а не только локально.

## Стек

- PHP 8.3+, Laravel 12, MySQL, Redis
- Laravel Sanctum — токены для веба и desktop-клиента, управление сессиями по устройствам
- Laravel Reverb — WebSocket-события: сообщения, присутствие, звонки и модерация
- LiveKit — голосовые комнаты через SFU; coturn — TURN/STUN для строгих сетей
- Web Push — уведомления о звонках и сообщениях, когда вкладка закрыта
- PHPUnit, PHPStan/Larastan, Laravel Pint

## Как устроен HTTP-слой

Типичный запрос проходит такой путь:

```text
роут → Form Request → контроллер → policy → сервис → API Resource
```

- **Роуты** лежат в `routes/api.php` и файлах внутри `routes/api/`. Они разбиты по доменам приложения: аккаунт, чаты, серверы, загрузки, поддержка, админка и интеграции.
- **Form Request** в `app/Http/Requests` валидирует входные данные и даёт контроллерам готовые методы вроде `$request->sessionId()` или `$request->uploadedFiles()`.
- **Контроллеры** в `app/Http/Controllers/API` сгруппированы по доменам приложения. Они остаются тонкими: принимают запрос, проверяют права, вызывают сервис и возвращают ресурс.
- **Policies** в `app/Policies` отвечают за доступ. Например, чужая сессия загрузки скрывается как `404`, а не просто запрещается через `403`.
- **Сервисы** в `app/Services` сгруппированы по тем же доменам, что и контроллеры. Они содержат бизнес-логику; для сложных результатов используются DTO и enum, а не массивы со строковыми статусами.
- **API Resources** в `app/Http/Resources` отвечают за стабильный формат ответов.

Подробная карта API лежит в [docs/API.md](docs/API.md).

## Что стоит посмотреть в коде

| Файл | Почему он интересен |
|---|---|
| `app/Services/Servers/ServerRoleService.php` | Роли и иерархия прав на сервере: пользователь может управлять только теми, кто ниже его роли |
| `app/Services/Servers/ServerChannelPermissionResolver.php` | Расчёт итоговых прав канала с учётом ролей, запретов и персональных разрешений |
| `app/Services/Conversations/LiveKitService.php` | Выдача токенов для голосовых комнат через SFU |
| `app/Services/Servers/ServerVoiceModerationService.php` | Серверная модерация голосовых каналов: mute, disconnect, move с проверкой прав |
| `app/Services/Uploads/UploadService.php` | Загрузка больших файлов частями, проверка offset и защита от параллельной записи |
| `app/Http/Responses/RangedFileResponse.php` | Потоковая отдача файлов с поддержкой `Range`, чтобы видео можно было перематывать |
| `app/Services/Conversations/CallService.php` | Жизненный цикл звонков и работа с несколькими устройствами пользователя |
| `app/Services/Account/DemoGuestService.php` | Демо-вход без регистрации с изолированными временными аккаунтами |
| `tests/Feature` и `tests/Unit` | Основные сценарии API, загрузок, звонков, ролей, прав и уведомлений |

## Запуск локально

```bash
cp .env.example .env
composer install
php artisan key:generate
php -r "echo base64_encode(random_bytes(32));"   # значение для ENCRYPTION_KEY_1 в .env
```

Для быстрого старта можно использовать SQLite: оставьте `DB_CONNECTION=sqlite` в `.env` и создайте пустой файл `database/database.sqlite`.

```bash
php artisan migrate --seed   # демо-пользователи: demo1@demo.local … demo3@demo.local, пароль password
php artisan serve            # HTTP API
php artisan reverb:start     # WebSocket
```

## Проверка качества

```bash
php artisan test                               # feature- и unit-тесты, SQLite in-memory
vendor/bin/phpstan analyse --memory-limit=1G   # Larastan, без baseline
vendor/bin/pint --test                         # стиль кода
```

В этой публичной копии GitHub Actions пока не настроен; проверки запускаются локально.

## Структура проекта

```text
app/
├── Broadcasting/          авторизация WebSocket-каналов
├── Console/Commands/      фоновые задачи: звонки, файлы, демо-аккаунты, токены
├── Data/                  DTO для результатов сервисов
├── Enums/                 статусы, исходы операций и битовые маски прав
├── Events/                события для Reverb
├── Exceptions/            API-исключения и ошибки загрузки
├── Http/
│   ├── Controllers/API/   контроллеры по доменам: Account, Conversations, Servers, Support
│   ├── Middleware/
│   ├── Requests/          валидация запросов
│   ├── Resources/         формат API-ответов
│   └── Responses/         специальные ответы, например Range-файлы
├── Models/                User в корне; остальные модели по доменам
├── Policies/
├── Services/              бизнес-логика по доменам: Account, Conversations, Servers, Support
└── Support/               небольшие вспомогательные классы
```

## Компромиссы

В первых миграциях проекта остались несколько старых названий таблиц и колонок, например `friend`, `channels_members`, `users_id` и `channels_id`. В новом коде это закрыто моделями и отношениями. В продакшене эти названия не переименовывались, чтобы не делать рискованную миграцию данных ради косметики.
