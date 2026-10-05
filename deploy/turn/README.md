# Свой TURN-сервер (coturn) для SonetCord

TURN нужен, чтобы звонок соединялся даже когда оба собеседника за симметричным
NAT / мобильным оператором (CGNAT) / строгим файрволом — там прямой P2P через
STUN не проходит, и трафик идёт транзитом через TURN-релей.

Раздачей серверов клиенту занимается
[`IceServersController`](../../app/Http/Controllers/API/Conversations/IceServersController.php):
если в `.env` задан `TURN_HOST`, он автоматически добавляет твой STUN+TURN в ответ
`/api/ice-servers`. Никаких правок кода не требуется — только поднять сервер и
заполнить `.env`.

## 1. Что нужно

- VPS с **публичным IP** (любой дешёвый подойдёт — TURN нетребователен к CPU,
  но ему нужен трафик/канал).
- Открытые порты на файрволе / в security-group провайдера:
  - `3478/udp` и `3478/tcp` — сигнализация TURN/STUN;
  - `5349/tcp` — TURN over TLS (`turns:`), помогает там, где режут обычный TURN;
  - `49152-65535/udp` — relay-диапазон медиа (он же в `turnserver.conf`).
- Docker + docker compose на сервере.

## 2. Настроить конфиг

В [`turnserver.conf`](./turnserver.conf) замени три плейсхолдера:

| Плейсхолдер | На что менять |
|---|---|
| `ЗАМЕНИ_НА_ДЛИННЫЙ_ПАРОЛЬ` в строке `user=zovcord:...` | случайный пароль (см. ниже) |
| `realm=turn.example.com` | домен твоего TURN-сервера (или публичный IP) |
| `external-ip=ЗАМЕНИ_НА_ПУБЛИЧНЫЙ_IP` | публичный IP VPS. За NAT: `ПУБЛИЧНЫЙ/ВНУТРЕННИЙ` |

Сгенерировать пароль:

```bash
openssl rand -hex 32
```

## 3. Запустить

Скопируй папку `deploy/turn/` на сервер и из неё:

```bash
docker compose up -d
docker compose logs -f      # проверить, что стартовал без ошибок
```

### Запуск с TLS (`turns:`)

Для TLS нужен домен, который указывает на VPS, и сертификат для этого домена.
Если у тебя уже есть Let's Encrypt сертификат, скопируй его в папку `deploy/turn/certs/`:

```bash
mkdir -p certs
sudo cp /etc/letsencrypt/live/ТВОЙ-ДОМЕН/fullchain.pem certs/fullchain.pem
sudo cp /etc/letsencrypt/live/ТВОЙ-ДОМЕН/privkey.pem certs/privkey.pem
sudo chmod 644 certs/fullchain.pem certs/privkey.pem
```

В [`turnserver.tls.conf`](./turnserver.tls.conf) замени:

| Плейсхолдер | На что менять |
|---|---|
| `CHANGE_ME_LONG_PASSWORD` | тот же пароль, что пойдёт в `TURN_CREDENTIAL` |
| `realm=turn.example.com` | домен с сертификатом, например `turn.example.com` |
| `external-ip=CHANGE_ME_PUBLIC_IP` | публичный IP VPS |

Запуск TLS-варианта:

```bash
docker compose -f docker-compose.yml -f docker-compose.tls.yml up -d
docker compose -f docker-compose.yml -f docker-compose.tls.yml logs -f
```

Если хочешь именно `turns:` на `443`, поменяй `tls-listening-port=5349` на
`tls-listening-port=443` и открой `443/tcp`. Но на одном IP этот порт не может
одновременно занимать nginx/сайт и coturn.

## 4. Прописать в .env бэкенда

В `.env` Laravel-приложения (значения из `turnserver.conf`):

```env
TURN_HOST=turn.example.com          # домен или IP сервера (БЕЗ схемы и порта)
TURN_USERNAME=zovcord               # часть до ":" в строке user=
TURN_CREDENTIAL=ДЛИННЫЙ_ПАРОЛЬ      # часть после ":" в строке user=
TURN_TLS_HOST=turn.example.com      # домен из TLS-сертификата
TURN_TLS_PORT=5349                  # или 443, если coturn слушает 443 на отдельном IP
```

Затем сбросить кэш конфигурации:

```bash
php artisan config:clear
```

## 5. Проверить, что TURN реально работает

1. Открой <https://icetest.info/> или Trickle ICE:
   <https://webrtc.github.io/samples/src/content/peerconnection/trickle-ice/>
2. Введи `turn:turn.example.com:3478`, username `zovcord`, password — твой пароль.
3. Нажми *Gather candidates*. Должны появиться кандидаты с типом **`relay`** —
   значит TURN отдаёт релей. Если `relay` нет — порты закрыты или неверный
   `external-ip`/креды.

Для TLS проверь отдельно:

```text
turns:turn.example.com:5349?transport=tcp
```

Также можно дёрнуть сам бэкенд — в ответе должен быть твой `turn:`-сервер:

```bash
curl https://твой-домен/api/ice-servers
```

## Заметки

- `turns:` отдаётся клиенту только если в `.env` заполнены `TURN_TLS_HOST` и
  `TURN_TLS_PORT`. Для TLS лучше использовать домен, а не голый IP: браузеры
  проверяют сертификат.
- Креды в конфиге статичные. Для продакшена надёжнее `use-auth-secret`
  (TURN REST API с временными токенами), но это требует доработки контроллера —
  скажи, если нужно.
