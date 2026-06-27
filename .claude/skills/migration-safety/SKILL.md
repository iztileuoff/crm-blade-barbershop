---
name: migration-safety
description: Safe migration patterns for this cross-DB project (prod MySQL, tests/dev SQLite :memory:). Use when writing, reviewing, or running any database migration — especially drops, FK changes, index swaps, or date logic.
---

# Migration Safety (MySQL prod / SQLite tests)

Этот проект гоняет миграции на **двух СУБД**: прод — MySQL (`crm_blade_barbershop`),
а тесты и часть dev — SQLite `:memory:` (см. `phpunit.xml`). То, что прошло на
SQLite, может упасть на MySQL и наоборот. Любую миграцию пиши так, чтобы она была
корректна на **обеих**, и прогоняй тесты (`php artisan test --compact`) перед пушем —
на проде `./deploy.sh` запускает `migrate --force` (см. `/deploy-checklist`).

> Авто-хук (`.claude/settings.local.json`, PostToolUse) подсвечивает при записи
> миграции: `MONTH()`/`YEAR()`/`DAYOFMONTH()`, zero-date, `dropColumn` без
> `dropForeign` в `up()`, и destructive-drop в `up()` без guard'а. Хук смотрит
> только тело `up()`, чтобы не шуметь на легитимных `down()`.

## 1. Drop колонки с FK — порядок и раздельные вызовы
MySQL отказывается дропать колонку, на которую ещё ссылается constraint; SQLite
оставляет «висячее» определение FK, если колонка ушла первой. Поэтому:

1. Сначала **`dropForeign`** — в своём `Schema::table()`.
2. Потом **`dropColumn`** — в **отдельном** `Schema::table()` (чтобы снятие
   constraint закоммитилось до дропа колонки).
3. Оборачивай в `hasColumn` / `hasTable` guard — миграция должна быть
   идемпотентной и переживать повторный прогон.

```php
public function up(): void
{
    if (! Schema::hasTable('appointments')) {
        return;
    }

    // 1. сначала снимаем constraint — отдельный вызов
    Schema::table('appointments', function (Blueprint $table) {
        if (Schema::hasColumn('appointments', 'barber_id')) {
            $table->dropForeign(['barber_id']);
        }
    });

    // 2. потом дропаем колонку — отдельный вызов
    Schema::table('appointments', function (Blueprint $table) {
        if (Schema::hasColumn('appointments', 'barber_id')) {
            $table->dropColumn('barber_id');
        }
    });
}
```
Всегда пиши рабочий `down()` (восстановление колонки/FK с `hasColumn`-guard).

**Колонка без FK** (например `services.price` в
`2026_05_05_113110_move_price_from_services_to_barbers_table.php`): `dropForeign`
не нужен, но `hasColumn`-guard всё равно делает миграцию идемпотентной —
хук пометит такой `dropColumn` именно из-за отсутствия guard'а.

## 2. Замена индекса — «сначала новый, потом drop»
Не дропай старый индекс до создания нового (иначе окно без индекса под нагрузкой).
Порядок: создать новый → переключить чтения → `dropIndex` старого под
**`hasIndex`**-guard. Так миграция безопасна и при повторном прогоне.

## 3. Даты: SQLite ≠ MySQL
- **Нет `MONTH()` / `YEAR()` / `DAYOFMONTH()` в SQLite** — тесты упадут.
  Пиши фильтры по дате **полуоткрытым диапазоном**: `>= $start AND < $end`,
  а не `whereRaw('MONTH(col) = ...')`. Это касается и запросов в моделях/
  компонентах, не только миграций.
- `datetime`-касты «сырых» значений различаются MySQL vs SQLite — сравнивай
  через диапазоны/`whereDate()`, не по точному равенству строки.
- Никаких zero-date литералов `'0000-00-00'` — невалидно в MySQL strict mode.
  Для «пустой» даты используй `nullable()` + `null`.

## 4. Перед коммитом миграции
- [ ] `up()` и `down()` идемпотентны (guard'ы `hasTable`/`hasColumn`/`hasIndex`).
- [ ] `php artisan test --compact` зелёный (тесты идут на SQLite — поймают
      кросс-БД даты сразу).
- [ ] `php artisan migrate:fresh --seed` на dev-БД (MySQL) проходит, `down()` тоже.
- [ ] Дат-логика в БД-нейтральном виде (полуоткрытые диапазоны).
- [ ] `vendor/bin/pint --dirty` (или авто-хук) — код отформатирован.
