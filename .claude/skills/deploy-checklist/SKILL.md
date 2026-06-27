---
name: deploy-checklist
description: Production deploy checklist for crm-blade-barbershop (blade-barbershop.uz, Linux single-node, ./deploy.sh, MySQL, Telegram webhook). Use when the user asks to deploy, prepare a release, run prod migrations, or review what is pending for production.
---

# Production Deploy Checklist (crm-blade-barbershop)

Цель — выкатить на прод **без сюрпризов**. Деплой автоматизирован скриптом
`./deploy.sh` (запускается на сервере), этот скилл — что проверить **до** и
**вокруг** него. Ничего на проде сам не выполняй — это процедура и список проверок.

## 0. Факты о проде
- **URL:** https://blade-barbershop.uz · **БД:** MySQL (`crm_blade_barbershop`).
- **Сервер:** Linux, single-node, SSH `iztileuoff_user@baltabekov`,
  путь `~/www/blade-barbershop.uz`. Деплой — `./deploy.sh` из корня репо.
- **Telegram-бот:** режим **webhook** (`POST /telegram/webhook`), отдельного
  процесса нет. «Перезапуск» = `nutgram:hook:set` + `nutgram:register-commands`,
  делается в `deploy.sh` при наличии `TELEGRAM_TOKEN` в `.env`.
- **`deploy.sh` отказоустойчив:** при любой ошибке сайт **остаётся в
  maintenance** (не выкатывает битую версию). Снимает maintenance только после
  успешного smoke. Восстановление: исправить и запустить `./deploy.sh` заново
  (или вручную `php artisan up`).

## 1. Что именно деплоится
1. `git log origin/main..main` и открытые issues (`gh issue list`) — что входит.
2. Выпиши **новые миграции** и затронутые **очереди/Telegram-команды**.
3. Есть ли ручные шаги (новые `.env`-переменные, индексы, перерегистрация бота)?

## 2. Перед пушем в main (локально / CI)
- [ ] `php artisan test --compact` — **зелёный**. На проде тесты НЕ гоняются:
      dev-зависимости не ставятся (`composer --no-dev`), а тесты используют
      `RefreshDatabase` и **затёрли бы продовую БД**. Их место — здесь, до пуша.
- [ ] Миграции прошли проверку: идемпотентны, кросс-БД (MySQL/SQLite),
      безопасные drop'ы — см. `/migration-safety`.
- [ ] `vendor/bin/pint --dirty --format agent` — код отформатирован.
- [ ] Если менялся фронтенд — `npm run build` локально без ошибок
      (`package-lock.json` в синхроне: `deploy.sh` использует `npm ci`).
- [ ] Новые `.env`-переменные добавлены на сервере **до** деплоя (deploy.sh
      кэширует конфиг — `config:cache`; отсутствующий ключ уедет в кэш пустым).

## 3. Что делает deploy.sh (для справки, выполняется на сервере)
`artisan down` (с secret для smoke) → `git reset --hard origin/main` →
`composer install --no-dev` → `npm ci && npm run build` → `migrate --force` →
`optimize:clear` + `config/route/view/event:cache` → `storage:link` →
`queue:restart` → (webhook) `nutgram:hook:set` + `register-commands` →
smoke (`artisan about`, `migrate:status`, HTTP `/login` == 200) → `artisan up`.

## 4. После деплоя
- [ ] Сайт снялся с maintenance (главная / `/login` отвечает 200).
- [ ] Если затронут бот — проверить, что webhook отвечает и команды на месте.
- [ ] Логи на ошибки рантайма/миграций (`php artisan pail` или прод-лог).
- [ ] Обновить соответствующую заметку в `memory/` при изменении процесса деплоя.

## 5. Откат
Прод — single-node без сине-зелёного. Откат = `git reset --hard <prev>` и
повторный `./deploy.sh`; **обратные миграции** (`down()`) применяй осознанно —
проверь, что `down()` существует и не теряет данные. Перед рискованной
миграцией — бэкап БД.
