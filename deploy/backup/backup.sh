#!/usr/bin/env bash
#
# Бэкап БД SonetCord: mysqldump -> gzip -> (опц. gpg) -> Яндекс.Диск -> письмо-отчёт.
# Запускать из cron под root. Конфиг: /etc/zovcord-backup.conf (chmod 600).
#
set -Eeuo pipefail

CONF="${ZOVCORD_BACKUP_CONF:-/etc/zovcord-backup.conf}"
[ -r "$CONF" ] || { echo "Не найден конфиг: $CONF" >&2; exit 1; }
# shellcheck disable=SC1090
. "$CONF"

: "${APP_DIR:?APP_DIR не задан в $CONF}"

YADISK_DIR="${YADISK_DIR:-app:/zovcord}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/zovcord}"
LOG_FILE="${LOG_FILE:-/var/log/zovcord-backup.log}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
LOCAL_RETENTION_DAYS="${LOCAL_RETENTION_DAYS:-3}"
MAIL_ON_SUCCESS="${MAIL_ON_SUCCESS:-1}"
GPG_PASSPHRASE="${GPG_PASSPHRASE:-}"
HEALTHCHECK_URL="${HEALTHCHECK_URL:-}"
API="https://cloud-api.yandex.net/v1/disk"

STAMP="$(date +%Y-%m-%d-%H%M)"
HOST="$(hostname -f 2>/dev/null || hostname)"
START_TS=$SECONDS

RUN_LOG="$(mktemp)"
mkdir -p "$BACKUP_DIR" "$(dirname "$LOG_FILE")"
chmod 700 "$BACKUP_DIR"
exec > >(tee -a "$LOG_FILE" "$RUN_LOG") 2>&1

WORK="$(mktemp -d)"
cleanup() { rm -rf "$WORK"; rm -f "$RUN_LOG"; }

log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }

need() { command -v "$1" >/dev/null 2>&1 || { log "ОШИБКА: нет команды '$1'"; exit 1; }; }

# --- URL-кодирование пути (в путях есть ':' и '/') ---------------------------
urlenc() {
  local s="$1" out='' c i
  for ((i = 0; i < ${#s}; i++)); do
    c="${s:i:1}"
    case "$c" in
      [a-zA-Z0-9.~_-]) out+="$c" ;;
      *) out+="$(printf '%%%02X' "'$c")" ;;
    esac
  done
  printf '%s' "$out"
}

yd() { curl -fsS -m 120 -H "Authorization: OAuth $YADISK_TOKEN" "$@"; }

# --- Почта -------------------------------------------------------------------
send_mail() {
  local subject="$1" body_file="$2" msg
  [ -n "${MAIL_TO:-}" ] || return 0
  msg="$WORK/mail.eml"
  {
    printf 'From: %s\n' "${MAIL_FROM:-$MAIL_TO}"
    printf 'To: %s\n' "$MAIL_TO"
    printf 'Subject: =?UTF-8?B?%s?=\n' "$(printf '%s' "$subject" | base64 | tr -d '\n')"
    printf 'Date: %s\n' "$(date -R)"
    printf 'MIME-Version: 1.0\n'
    printf 'Content-Type: text/plain; charset=UTF-8\n'
    printf 'Content-Transfer-Encoding: 8bit\n\n'
    cat "$body_file"
  } > "$msg"

  if [ -n "${SMTP_HOST:-}" ]; then
    curl -fsS -m 60 --url "smtps://${SMTP_HOST}:${SMTP_PORT:-465}" --ssl-reqd \
      --user "${SMTP_USER}:${SMTP_PASS}" \
      --mail-from "${MAIL_FROM:-$SMTP_USER}" --mail-rcpt "$MAIL_TO" \
      --upload-file "$msg" && { log "Письмо отправлено на $MAIL_TO"; return 0; }
    log "ПРЕДУПРЕЖДЕНИЕ: не удалось отправить письмо через $SMTP_HOST"
  elif command -v sendmail >/dev/null 2>&1; then
    sendmail -t < "$msg" && { log "Письмо отправлено через sendmail"; return 0; }
  else
    log "ПРЕДУПРЕЖДЕНИЕ: почта не настроена (нет SMTP_HOST и sendmail)"
  fi
  return 0
}

on_error() {
  local code=$? line=$1
  log "ПРОВАЛ на строке $line (код $code)"
  {
    printf 'Бэкап SonetCord ПРОВАЛЕН.\n\n'
    printf 'Сервер : %s\n' "$HOST"
    printf 'Время  : %s\n' "$(date '+%F %T %Z')"
    printf 'Строка : %s (код выхода %s)\n\n' "$line" "$code"
    printf -- '--- последние 60 строк лога ---\n'
    tail -n 60 "$RUN_LOG"
  } > "$WORK/body.txt"
  send_mail "❌ Бэкап SonetCord ПРОВАЛЕН ($HOST)" "$WORK/body.txt"
  cleanup
  exit "$code"
}
trap 'on_error $LINENO' ERR
trap cleanup EXIT

# --- Проверки ----------------------------------------------------------------
need curl; need jq; need mysqldump; need gzip

ENV_FILE="$APP_DIR/.env"
[ -r "$ENV_FILE" ] || { log "ОШИБКА: не читается $ENV_FILE"; exit 1; }

env_get() {
  grep -E "^${1}=" "$ENV_FILE" | tail -1 | cut -d= -f2- \
    | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

DB_HOST="$(env_get DB_HOST)"; DB_PORT="$(env_get DB_PORT)"
DB_NAME="$(env_get DB_DATABASE)"; DB_USER="$(env_get DB_USERNAME)"
DB_PASS="$(env_get DB_PASSWORD)"
: "${DB_NAME:?DB_DATABASE пуст в .env}"

# Почта: если в конфиге не задана явно, берём рабочие креды приложения из .env —
# один пароль в одном месте, не разъедется при смене.
SMTP_HOST="${SMTP_HOST:-$(env_get MAIL_HOST)}"
SMTP_PORT="${SMTP_PORT:-$(env_get MAIL_PORT)}"
SMTP_USER="${SMTP_USER:-$(env_get MAIL_USERNAME)}"
SMTP_PASS="${SMTP_PASS:-$(env_get MAIL_PASSWORD)}"
MAIL_FROM="${MAIL_FROM:-$(env_get MAIL_FROM_ADDRESS)}"
MAIL_TO="${MAIL_TO:-$MAIL_FROM}"

if [ -n "$GPG_PASSPHRASE" ]; then need gpg; fi

# --- OAuth: обновление access-токена ----------------------------------------
# Access-токен Яндекса живёт ограниченное время. Если задан refresh_token,
# берём свежий на каждом запуске — иначе однажды всё встанет с 401.
# Яндекс выдаёт НОВЫЙ refresh_token и гасит старый, поэтому его надо сразу
# записать обратно в конфиг.
if [ -n "${YADISK_REFRESH_TOKEN:-}" ] && [ -n "${YADISK_CLIENT_ID:-}" ]; then
  log "Обновление access-токена по refresh_token"
  RESP="$(curl -fsS -m 30 https://oauth.yandex.ru/token \
    -d grant_type=refresh_token \
    -d refresh_token="$YADISK_REFRESH_TOKEN" \
    -d client_id="$YADISK_CLIENT_ID" \
    -d client_secret="${YADISK_CLIENT_SECRET:-}")"
  YADISK_TOKEN="$(printf '%s' "$RESP" | jq -r '.access_token // empty')"
  NEW_REFRESH="$(printf '%s' "$RESP" | jq -r '.refresh_token // empty')"
  [ -n "$YADISK_TOKEN" ] || { log "ОШИБКА: Яндекс не вернул access_token: $RESP"; exit 1; }
  if [ -n "$NEW_REFRESH" ] && [ "$NEW_REFRESH" != "$YADISK_REFRESH_TOKEN" ]; then
    sed -i.bak -E "s|^YADISK_REFRESH_TOKEN=.*|YADISK_REFRESH_TOKEN=\"$NEW_REFRESH\"|" "$CONF"
    grep -q "$NEW_REFRESH" "$CONF" \
      && { rm -f "$CONF.bak"; log "Новый refresh_token сохранён в $CONF"; } \
      || { log "ОШИБКА: не удалось сохранить refresh_token в $CONF"; exit 1; }
  fi
fi
[ -n "${YADISK_TOKEN:-}" ] || { log "ОШИБКА: нет YADISK_TOKEN и нет YADISK_REFRESH_TOKEN в $CONF"; exit 1; }

log "=== Старт бэкапа: база '$DB_NAME' на ${DB_HOST:-127.0.0.1}:${DB_PORT:-3306} ==="

# Пароль отдаём файлом, а не аргументом: аргументы видны в `ps` любому процессу.
CNF="$WORK/my.cnf"
umask 077
cat > "$CNF" <<EOC
[client]
host=${DB_HOST:-127.0.0.1}
port=${DB_PORT:-3306}
user=${DB_USER}
password=${DB_PASS}
EOC

# --- Дамп --------------------------------------------------------------------
DUMP="$BACKUP_DIR/db-$STAMP.sql.gz"
log "Дамп -> $DUMP"
mysqldump --defaults-extra-file="$CNF" \
  --single-transaction --quick --routines --triggers --events \
  --set-gtid-purged=OFF --default-character-set=utf8mb4 \
  --no-tablespaces \
  "$DB_NAME" | gzip -6 > "$DUMP"

# mysqldump может упасть в середине, а gzip всё равно закроет валидный архив.
# Поэтому проверяем и целостность gzip, и финальный маркер самого дампа.
gzip -t "$DUMP"
gzip -dc "$DUMP" | tail -c 200 | grep -q 'Dump completed' \
  || { log "ОШИБКА: в дампе нет маркера 'Dump completed' — файл обрезан"; exit 1; }

TABLES="$(gzip -dc "$DUMP" | grep -c '^CREATE TABLE' || true)"
log "Дамп корректен: $TABLES таблиц, $(du -h "$DUMP" | cut -f1)"

# .env — в нём APP_KEY, без него не расшифровать сообщения и сессии.
ENV_COPY="$BACKUP_DIR/env-$STAMP.txt.gz"
gzip -c "$ENV_FILE" > "$ENV_COPY"

FILES=("$DUMP" "$ENV_COPY")

# --- Вложения ----------------------------------------------------------------
# Раз в неделю: файлы меняются медленно, а весят на порядки больше базы.
if [ "${BACKUP_STORAGE:-0}" = "1" ] && [ "$(date +%u)" = "${STORAGE_WEEKDAY:-7}" ]; then
  STOR="$BACKUP_DIR/storage-$STAMP.tar.gz"
  # Берём только файлы не крупнее порога шифрования приложения
  # (uploads.encrypt_max_bytes, 25 МБ): крупные лежат на диске открытыми,
  # их и так отдают потоком, а в архиве они дают основной вес.
  SKIP_MB="${STORAGE_MAX_FILE_MB:-25}"
  SKIPPED_N=$(cd "$APP_DIR" && find storage/app -type f -size +"${SKIP_MB}"M | wc -l)
  SKIPPED_MB=$(cd "$APP_DIR" && find storage/app -type f -size +"${SKIP_MB}"M -printf '%s\n' \
    | awk '{t+=$1} END {printf "%.0f", t/1048576}')
  log "Архив вложений (только файлы <= ${SKIP_MB} МБ) -> $STOR"
  [ "${SKIPPED_N:-0}" -gt 0 ] && log "Пропущено крупных файлов: $SKIPPED_N (${SKIPPED_MB:-0} МБ)"
  # Приложение пишет в storage во время архивации; «file changed as we read it»
  # это не порча архива, а гонка — прерывать из-за неё бэкап нельзя.
  if ! (cd "$APP_DIR" && find storage/app -type f ! -size +"${SKIP_MB}"M -print0 \
        | tar czf "$STOR" --null -T - --warning=no-file-changed) 2>"$WORK/tar.err"; then
    if grep -q 'file changed as we read it\|Removing leading' "$WORK/tar.err"; then
      log "ПРЕДУПРЕЖДЕНИЕ: часть файлов менялась во время архивации"
    else
      log "ОШИБКА tar: $(head -3 "$WORK/tar.err")"; exit 1
    fi
  fi
  tar tzf "$STOR" >/dev/null || { log "ОШИБКА: архив вложений повреждён"; exit 1; }
  log "Вложений: $(tar tzf "$STOR" | wc -l) файлов, $(du -h "$STOR" | cut -f1)"
  FILES+=("$STOR")
fi

# --- Шифрование --------------------------------------------------------------
if [ -n "$GPG_PASSPHRASE" ]; then
  ENC=()
  for f in "${FILES[@]}"; do
    gpg --batch --yes --quiet --symmetric --cipher-algo AES256 \
      --passphrase "$GPG_PASSPHRASE" --output "$f.gpg" "$f"
    rm -f "$f"
    ENC+=("$f.gpg")
  done
  FILES=("${ENC[@]}")
  log "Файлы зашифрованы (AES256)"
else
  log "ПРЕДУПРЕЖДЕНИЕ: GPG_PASSPHRASE пуст — переписка уедет в облако без шифрования"
fi

# --- Заливка на Яндекс.Диск --------------------------------------------------
# Папка может уже существовать (409) — это не ошибка.
curl -s -m 60 -o /dev/null -X PUT -H "Authorization: OAuth $YADISK_TOKEN" \
  "$API/resources?path=$(urlenc "$YADISK_DIR")" || true

UPLOADED=()
for f in "${FILES[@]}"; do
  name="$(basename "$f")"
  dest="$YADISK_DIR/$name"
  log "Загрузка $name ($(du -h "$f" | cut -f1))"

  href="$(yd "$API/resources/upload?path=$(urlenc "$dest")&overwrite=true" | jq -r '.href')"
  [ -n "$href" ] && [ "$href" != "null" ] || { log "ОШИБКА: API не вернул ссылку для $name"; exit 1; }

  # На подписанную ссылку токен слать не нужно.
  curl -fsS -m 3600 -T "$f" "$href"

  # Сверяем размер: заливка может завершиться «успешно» на оборванном соединении.
  local_size="$(stat -c%s "$f" 2>/dev/null || stat -f%z "$f")"
  remote_size="$(yd "$API/resources?path=$(urlenc "$dest")&fields=size" | jq -r '.size')"
  [ "$local_size" = "$remote_size" ] \
    || { log "ОШИБКА: размер не совпал ($local_size != $remote_size)"; exit 1; }
  log "OK: $name, $remote_size байт"
  UPLOADED+=("$name")
done

# --- Ротация на Диске --------------------------------------------------------
CUTOFF="$(date -u -d "-${RETENTION_DAYS} days" +%Y-%m-%d 2>/dev/null \
       || date -u -v-"${RETENTION_DAYS}"d +%Y-%m-%d)"
DELETED=0
while read -r name; do
  [ -n "$name" ] || continue
  fdate="$(printf '%s' "$name" | grep -oE '[0-9]{4}-[0-9]{2}-[0-9]{2}' | head -1)" || true
  [ -n "$fdate" ] || continue
  if [[ "$fdate" < "$CUTOFF" ]]; then
    # permanently=true — иначе файлы уедут в Корзину и продолжат есть квоту.
    yd -X DELETE -o /dev/null \
      "$API/resources?path=$(urlenc "$YADISK_DIR/$name")&permanently=true" || true
    log "Удалён старый: $name"
    DELETED=$((DELETED + 1))
  fi
done < <(yd "$API/resources?path=$(urlenc "$YADISK_DIR")&limit=1000&sort=created&fields=_embedded.items.name,_embedded.items.type" \
         | jq -r '._embedded.items[]? | select(.type=="file") | .name')

find "$BACKUP_DIR" -type f -mtime +"$LOCAL_RETENTION_DAYS" -delete 2>/dev/null || true

# --- Отчёт -------------------------------------------------------------------
DISK_JSON="$(yd "$API/" || echo '{}')"
USED="$(printf '%s' "$DISK_JSON" | jq -r '(.used_space // 0) / 1073741824 | floor')"
TOTAL="$(printf '%s' "$DISK_JSON" | jq -r '(.total_space // 0) / 1073741824 | floor')"
COUNT="$(yd "$API/resources?path=$(urlenc "$YADISK_DIR")&limit=1000&fields=_embedded.items.name,_embedded.items.type" \
        | jq -r '[._embedded.items[]? | select(.type=="file")] | length')"
ELAPSED=$((SECONDS - START_TS))

{
  printf 'Бэкап SonetCord выполнен успешно.\n\n'
  printf 'Сервер      : %s\n' "$HOST"
  printf 'Время       : %s (заняло %s c)\n' "$(date '+%F %T %Z')" "$ELAPSED"
  printf 'База        : %s (%s таблиц)\n' "$DB_NAME" "$TABLES"
  printf 'Шифрование  : %s\n' "$([ -n "$GPG_PASSPHRASE" ] && echo 'да, AES256' || echo 'НЕТ')"
  printf '\nЗагружено на Яндекс.Диск (%s):\n' "$YADISK_DIR"
  for n in "${UPLOADED[@]}"; do printf '  • %s\n' "$n"; done
  if [ -n "${SKIPPED_N:-}" ] && [ "${SKIPPED_N:-0}" -gt 0 ]; then
    printf '\nНЕ в бэкапе : %s файлов крупнее %s МБ (%s МБ) — их надо копировать отдельно\n' \
      "$SKIPPED_N" "${SKIP_MB}" "${SKIPPED_MB:-0}"
  fi
  printf '\nХранение    : %s дней, удалено старых: %s\n' "$RETENTION_DAYS" "$DELETED"
  printf 'Всего копий : %s\n' "$COUNT"
  printf 'Диск занят  : %s из %s ГБ\n' "$USED" "$TOTAL"
  printf '\nВосстановление: см. deploy/backup/README.md\n'
} > "$WORK/body.txt"

log "=== Готово за ${ELAPSED}s ==="

if [ "$MAIL_ON_SUCCESS" = "1" ]; then
  send_mail "✅ Бэкап SonetCord ($HOST, $STAMP)" "$WORK/body.txt"
fi
if [ -n "$HEALTHCHECK_URL" ]; then
  curl -fsS -m 20 "$HEALTHCHECK_URL" -o /dev/null || true
fi

exit 0
