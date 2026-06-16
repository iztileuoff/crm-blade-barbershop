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

log() {
    printf '\n\033[1;32m==> %s\033[0m\n' "$1"
}

# Работать всегда из каталога, где лежит скрипт.
cd "$(dirname "$0")"

log "Включаем maintenance-режим"
$PHP_BIN artisan down --render="errors::503" --retry=15 || true

# Снять maintenance-режим в любом случае при выходе.
trap '$PHP_BIN artisan up || true' EXIT

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
    APP_URL_VALUE="$(grep -E '^APP_URL=' .env | head -1 | cut -d= -f2- | tr -d "\"'" | sed 's:/*$::')"
    WEBHOOK_URL="${TELEGRAM_WEBHOOK_URL:-${APP_URL_VALUE}/telegram/webhook}"
    $PHP_BIN artisan nutgram:hook:set "$WEBHOOK_URL"
    $PHP_BIN artisan nutgram:register-commands
else
    log "TELEGRAM_TOKEN не задан — настройку бота пропускаем"
fi

log "Деплой завершён"
# trap выше снимет maintenance-режим (artisan up).
