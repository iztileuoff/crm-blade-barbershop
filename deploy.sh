#!/usr/bin/env bash
#
# Деплой crm-blade-barbershop на production-сервер (Linux).
# Запускать из корня проекта на сервере: ./deploy.sh
#
# Прерывать выполнение при любой ошибке, незаданной переменной или
# ошибке в пайпе.
set -euo pipefail

# --- Настройки (можно переопределить через окружение) ---------------------
BRANCH="${DEPLOY_BRANCH:-main}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
RUN_HTTP_SMOKE="${RUN_HTTP_SMOKE:-1}"

log() {
    printf '\n\033[1;32m==> %s\033[0m\n' "$1"
}

err() {
    printf '\n\033[1;31m%s\033[0m\n' "$1" >&2
}

# Работать всегда из каталога, где лежит скрипт.
cd "$(dirname "$0")"

# Базовый URL приложения (для smoke-проверки и webhook): из SMOKE_URL/APP_URL,
# без кавычек и хвостового слэша.
APP_URL_VALUE="$(grep -E '^APP_URL=' .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d "\"'" | sed 's:/*$::')"
SMOKE_URL="${SMOKE_URL:-$APP_URL_VALUE}"

# Секрет для обхода maintenance-режима во время smoke-проверки.
MAINTENANCE_SECRET="$($PHP_BIN -r 'echo bin2hex(random_bytes(16));' 2>/dev/null || echo "deploy-bypass-$$")"

# Снимаем maintenance ТОЛЬКО при успешном деплое; при любой ошибке оставляем
# сайт в maintenance, чтобы не выкатить «битую» версию.
DEPLOY_OK=0
cleanup() {
    if [[ "$DEPLOY_OK" -eq 1 ]]; then
        $PHP_BIN artisan up || true
    else
        err "!!! Деплой НЕ завершён — сайт оставлен в maintenance-режиме."
        err "!!! Исправьте ошибку и запустите ./deploy.sh заново (или вручную: php artisan up)."
    fi
}
trap cleanup EXIT

log "Включаем maintenance-режим"
$PHP_BIN artisan down --render="errors::503" --retry=15 --secret="$MAINTENANCE_SECRET" || true

log "Получаем свежий код из ветки $BRANCH"
git fetch --all --prune
git checkout "$BRANCH"
git reset --hard "origin/$BRANCH"

log "Устанавливаем PHP-зависимости"
$COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction --prefer-dist

log "Устанавливаем и собираем фронтенд"
npm ci
npm run build

log "Применяем миграции"
$PHP_BIN artisan migrate --force

log "Чистим и прогреваем кэш"
$PHP_BIN artisan optimize:clear
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
$PHP_BIN artisan event:cache

log "Создаём симлинк storage (если ещё нет)"
$PHP_BIN artisan storage:link || true

log "Перезапускаем очереди"
$PHP_BIN artisan queue:restart

# --- Telegram-бот (nutgram) ----------------------------------------------
# Бот работает в режиме webhook (POST /telegram/webhook), отдельного
# процесса нет — «перезапуск» = заново зарегистрировать webhook и меню
# команд в Telegram. Пропускаем, если токен не задан.
if grep -qE '^TELEGRAM_TOKEN=.+' .env 2>/dev/null; then
    log "Перерегистрируем Telegram-бота (webhook + команды)"
    WEBHOOK_URL="${TELEGRAM_WEBHOOK_URL:-${APP_URL_VALUE}/telegram/webhook}"
    $PHP_BIN artisan nutgram:hook:set "$WEBHOOK_URL"
    $PHP_BIN artisan nutgram:register-commands
else
    log "TELEGRAM_TOKEN не задан — настройку бота пропускаем"
fi

# --- Smoke-проверка перед снятием maintenance ----------------------------
# Полноценный тест-сьют (Pest) на проде НЕ запускаем: dev-зависимости не
# установлены (composer --no-dev), а тесты используют RefreshDatabase и
# затёрли бы продовую БД. Их место — в CI/локально до пуша. Здесь — лёгкая
# проверка, что новый код реально поднимается и отдаёт страницы.

log "Smoke: приложение загружается"
$PHP_BIN artisan about >/dev/null

log "Smoke: база данных доступна"
$PHP_BIN artisan migrate:status >/dev/null

if [[ "$RUN_HTTP_SMOKE" == "1" && -n "$SMOKE_URL" ]]; then
    log "Smoke: HTTP-ответ ($SMOKE_URL)"
    COOKIE_JAR="$(mktemp)"
    # Обходим maintenance через секрет, выставленный при artisan down.
    curl -fsS --max-time 15 -c "$COOKIE_JAR" -o /dev/null "${SMOKE_URL}/${MAINTENANCE_SECRET}" || true
    SMOKE_PATH="/login"
    HTTP_CODE="$(curl -s --max-time 15 -b "$COOKIE_JAR" -o /dev/null -w '%{http_code}' "${SMOKE_URL}${SMOKE_PATH}")" || true
    rm -f "$COOKIE_JAR"
    if [[ "$HTTP_CODE" != "200" ]]; then
        err "Smoke-проверка не прошла: ${SMOKE_URL}${SMOKE_PATH} вернул HTTP $HTTP_CODE"
        exit 1
    fi
    echo "  OK  ${SMOKE_URL}${SMOKE_PATH} -> $HTTP_CODE"
else
    log "HTTP smoke-проверка отключена (RUN_HTTP_SMOKE=0)"
fi

# Все проверки прошли — разрешаем cleanup-trap снять maintenance (artisan up).
DEPLOY_OK=1
log "Деплой завершён"
