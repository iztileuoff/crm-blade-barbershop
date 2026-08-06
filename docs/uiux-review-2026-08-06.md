# UI/UX ревью — 6 августа 2026

Полное UI/UX-ревью всех страниц CRM: 21 страница, оболочка (навигация/макеты/компоненты), плюс три сквозных разреза — опыт роли мастера, поведение при сбоях (сеть/сессия), путь клиента после брони.

**Как получено:** 11 независимых ревьюеров по областям + факт-чекеры для каждого critical/high-вывода + критик полноты покрытия, нашедший 3 слепых зоны (по ним прошёл отдельный круг). Итого 150 выводов; **1 отклонён факт-чеком** (помечен ниже), **149 подтверждены**: ≈16 critical, ≈55 high, остальное medium/low.

**Легенда:** severity `critical|high|medium|low` · категория (`pagination-scale`, `clarity`, `convenience`, `feedback`, `consistency`, `responsive`, `accessibility`, `i18n`, `performance-ux`) · effort `S` (< 1 ч) / `M` (полдня) / `L` (день+).

**Эталонные паттерны приложения** (копировать, не изобретать): кнопка сохранения `orders.blade.php:559-563` (spinner + disabled), пагинация `sms/history.blade.php`, `wire:confirm` с интерполяцией имени, тост `dispatch('saved')` + Alpine (settings/clients).

План работ разбит на приоритеты P0–P4 и заведён issues — карта в конце файла.

---
## Область: dashboard

### [high · pagination-scale · M] Month tab materializes 4+ full-month collections and aggregates them in PHP, re-running every 60s

The month tab loads every completed appointment of the month (`->get()` at line 381), every order of the month (line 393), every debt payment of the month (line 409), and then `dailyChartData` issues two MORE queries (lines 526-534) that return exactly the same rows already in memory. Every KPI (`monthlyServiceRevenue`, `monthlyCashTotal`, `monthlyReceivedTotal`, `monthlyDebtIssued`, `monthlyNotCollected`) is a PHP `sum()` over those hydrated collections rather than a DB aggregate. The whole component sits on `wire:poll.60s` (line 565), so a front-desk tablet left open on the month tab repeats all of it every minute. For a 4-chair shop at ~40 visits/day this is ~2,400 hydrated models per render, doubling as the shop grows. The day tab also eager-loads `services` (line 71) which is never rendered anywhere in the template - a wasted pivot query on every poll.

**Рекомендация.** Compute scalar KPIs with DB aggregates instead of collection sums (e.g. `Appointment::query()->where(...)->selectRaw('sum(price) rev, count(*) c')->first()`), and keep hydrated collections only for the payroll table that genuinely needs the per-row accessors. Feed `dailyChartData` from the already-loaded `$this->monthlyAppointments`/`$this->monthlyOrders` instead of re-querying. Drop `'services'` from the `with()` at line 71. Scope `wire:poll.60s` to a nested wrapper around the day-tab KPI cards so the expensive month view is not recomputed on a timer.

**Код:** resources/views/livewire/pages/admin/dashboard.blade.php:381, resources/views/livewire/pages/admin/dashboard.blade.php:393, resources/views/livewire/pages/admin/dashboard.blade.php:526, resources/views/livewire/pages/admin/dashboard.blade.php:71, resources/views/livewire/pages/admin/dashboard.blade.php:565

### [high · performance-ux · S] Revenue chart re-parses every row once per day of the month (O(days x rows) Carbon::parse)

`dailyChartData` loops days 1..31 and, inside each iteration, filters the entire month collection calling `Carbon::parse($a->starts_at)->toDateString()` per row (lines 540 and 544). With 1,200 appointments and 400 orders that is ~50,000 Carbon constructions per render - and it runs again on every 60s poll and on every month-picker change. `starts_at`/`created_at` are already Carbon casts, so the parse is pure waste on top of the wrong algorithm.

**Рекомендация.** Group once, then read the buckets: `$byDay = $this->monthlyAppointments->groupBy(fn ($a) => $a->starts_at->toDateString());` and `$ordersByDay = $this->monthlyOrders->groupBy(fn ($o) => $o->created_at->toDateString());`, then inside the day loop use `(int) ($byDay[$date] ?? collect())->sum('price')`. Even better, aggregate in SQL with `selectRaw('date(starts_at) as d, sum(price) as total')->groupBy('d')`.

**Код:** resources/views/livewire/pages/admin/dashboard.blade.php:536, resources/views/livewire/pages/admin/dashboard.blade.php:540, resources/views/livewire/pages/admin/dashboard.blade.php:544

### [high · feedback · S] No loading indicator anywhere, although date/month pickers are wire:model.live and trigger a full recompute

The date input (line 608) and month input (line 614) are `wire:model.live` and each change re-runs the whole month/day aggregation and re-renders ~800 lines of DOM. There is not a single `wire:loading` on this page, unlike the rest of the app (orders.blade.php:559-561 and sms/settings.blade.php:139-144 both show spinner + disabled state). On a front-desk tablet over mobile data the staff tap a date, see nothing change for a second or more, and tap again. The only motion feedback is the permanently animating green 'auto refresh' pip (lines 619-625), which pulses identically whether the data was refreshed 5 seconds or 20 minutes ago (e.g. after the network dropped), so it actively suggests freshness it cannot guarantee.

**Рекомендация.** Mirror the house pattern: add `wire:loading.class="opacity-40 pointer-events-none"` on the KPI grid and tables with `wire:target="date,month,activeTab"`, plus the standard spinner SVG next to the picker (`<svg wire:loading wire:target="date,month" class="h-4 w-4 animate-spin">` as in sms/settings.blade.php:142). Replace the decorative pip label with a rendered timestamp, e.g. `{{ __('dashboard.auto_refresh') }} · {{ now('Asia/Tashkent')->format('H:i') }}`, so staff can see when the numbers were actually last computed.

**Код:** resources/views/livewire/pages/admin/dashboard.blade.php:608, resources/views/livewire/pages/admin/dashboard.blade.php:614, resources/views/livewire/pages/admin/dashboard.blade.php:619

### [high · convenience · S] No prev/next/today stepping for the period, and switching tabs silently resets the period

The only way to change the reporting period is the native `<input type=date>` / `<input type=month>` (lines 606-616). The single most frequent dashboard action - 'compare yesterday' or 'go back to today after browsing' - takes 3-4 taps through the OS date wheel on a phone. Worse, `$date` and `$month` are independent (set once in `mount()`, lines 25-30): a manager looking at 15 May in the Day tab who taps 'Oy' gets the CURRENT month, not May, with no indication the period jumped. Coming back to 'Kun' likewise still shows May while the month card shows August - two tabs of the same screen reporting different periods.

**Рекомендация.** Add `previousPeriod()`, `nextPeriod()` and `goToToday()` methods and render them as h-10/w-10 arrow buttons plus a 'Bugun' pill either side of the picker (same rounded-xl border-content/10 styling as the input). Keep the tabs in sync by adding `public function updatedDate(): void { $this->month = substr($this->date, 0, 7); }` and, when switching to the day tab from a non-current month, snapping `$date` into that month. Disable/hide 'next' when the period is already the current one.

**Код:** resources/views/livewire/pages/admin/dashboard.blade.php:606, resources/views/livewire/pages/admin/dashboard.blade.php:25, resources/views/livewire/pages/admin/dashboard.blade.php:582

### [high · consistency · S] Month parsing is unguarded in all four data methods while the day path is protected, giving a 500 or 1969 data

`dayStart()` deliberately wraps `Carbon::parse` in try/catch because `$date` is a client-writable field (lines 36-43), and `monthString()` does the same (lines 54-58). But the four methods that actually produce the month numbers - `monthlyAppointments` (line 373), `monthlyOrders` (line 387), `monthlyDebtPayments` (line 402) and `dailyChartData` (line 523) - parse `$this->month.'-01'` with no guard at all. Verified behaviour: clearing the month input in the browser sends `''`, and `Carbon::parse('-01')` resolves to 1969-12-01 - so the header (guarded, falls back to now) shows the current month while every table and card below it shows an empty December 1969. A value like `2026-13` throws `InvalidFormatException` and white-screens the whole payroll page.

**Рекомендация.** Extract a `private function monthStart(): Carbon` with the identical try/catch fallback used by `dayStart()` (lines 36-43), returning `Carbon::now('Asia/Tashkent')->startOfMonth()` on failure, and call it from all four methods plus `monthString()` so the header and the data can never disagree.

**Код:** resources/views/livewire/pages/admin/dashboard.blade.php:373, resources/views/livewire/pages/admin/dashboard.blade.php:36, resources/views/livewire/pages/admin/dashboard.blade.php:523

### [high · clarity · M] Billed money, received money and two kinds of profit sit side by side with no explanation

The screen carries at least six money concepts that non-technical staff cannot be expected to separate: day KPI 'Kunlik tushum' is money RECEIVED (line 644), while the barber table column 'Tushum/revenue' right below it is money BILLED (lines 834, 886) with a separate 'Mijoz to'lagan' column for received (line 835). The month tab shows 'Oylik aylanma' (billed, line 980) in one card row and cash/card RECEIVED (lines 1022, 1034) in the next row with nothing labelling the difference. 'Kompaniya foydasi' (turnover minus salary, includes money not yet collected) sits 3 lines away from 'Kassadagi foyda' and 'Yig'ilmagan' in 11px text (lines 1110-1131) - staff will read the big number as cash on hand. Separately, the `%` under each barber's salary (line 928) and the 'Maosh %' column (line 1326) are NOT the barber's rate: they are back-computed as `salary / received * 100` (lines 319-321), so a barber known to be on 45% can display 43% with no explanation, which will be reported as a bug.

**Рекомендация.** Group the month cards under two small section headings (e.g. 'Hisoblangan (aylanma)' vs 'Qabul qilingan (kassa)') using the existing text-xs uppercase tracking-widest style, and add a `title="..."` hint (new `dashboard.hint_*` lang keys) on each KPI label plus a small `?` badge reusing the existing rounded-full bg-content/[0.06] pill. Rename the derived percent to something explicit ('Amaldagi %' / 'Fakt %') and give it a title explaining it is calculated salary divided by received money, not the barber's configured rate.

**Код:** resources/views/livewire/pages/admin/dashboard.blade.php:644, resources/views/livewire/pages/admin/dashboard.blade.php:1119, resources/views/livewire/pages/admin/dashboard.blade.php:928, resources/views/livewire/pages/admin/dashboard.blade.php:319

### [high · responsive · M] Two 7-column payroll tables rely on horizontal scroll only, so the barber name scrolls off the screen

Both the daily performance table (7 columns, lines 828-838) and the monthly salary table (7 columns, lines 1242-1252) use `px-6 py-4` cells inside a plain `overflow-x-auto` (lines 827, 1241) with no column hiding and no sticky first column. On a 390px phone the table is roughly 2.5 screens wide: by the time the cashier scrolls to 'Maosh' or 'Qoldiq' the avatar/name column is gone, so the salary figures cannot be attributed to a barber. The app already solves this elsewhere - appointments.blade.php hides secondary columns with `hidden md:table-cell` (lines 950-951) and folds the hidden data into a `md:hidden` sub-line inside the primary cell (line 978).

**Рекомендация.** Apply the appointments.blade.php idiom: mark 'Bekor qilingan', the cash/card badge row and 'Qoldiq' as `hidden md:table-cell`, and add a `md:hidden` sub-line under the barber name showing salary and remainder. Add `sticky left-0 z-10 bg-surface` (or the same bg used by the row) to the name `<td>`/`<th>` so it stays visible while the money columns scroll. Reduce padding to `px-3 sm:px-6` on small screens.

**Код:** resources/views/livewire/pages/admin/dashboard.blade.php:827, resources/views/livewire/pages/admin/dashboard.blade.php:1241, resources/views/livewire/pages/admin/dashboard.blade.php:843

### [medium · convenience · S] Appointment KPI cards are dead ends - 'Tasdiqlash kerak' cannot be acted on

The 'Kutilmoqda / Tasdiqlash kerak' card (lines 725-734) is an explicit call to action showing how many appointments need confirming, but neither it nor the total/completed/cancelled cards (lines 713-758) are clickable. The page proves the pattern works: the debt banner links to `route('admin.debts')` (line 704) and the product sales card links to `route('admin.orders')` (line 769). Staff must instead read the number, open the nav, go to Yozuvlar, and re-pick the same date the dashboard already had selected.

**Рекомендация.** Wrap each of the 4 stat cards in `<a href="{{ route('admin.appointments', ['date' => $date]) }}" class="block ...">` (add `hover:border-brass/30 transition` to match the existing card hover language), and have appointments.blade.php `mount()` (lines 97-109) seed `$this->date = request()->query('date', Carbon::now()->toDateString())` so the destination opens on the same day the dashboard was showing.

**Код:** resources/views/livewire/pages/admin/dashboard.blade.php:725, resources/views/livewire/pages/admin/dashboard.blade.php:704, resources/views/livewire/pages/admin/dashboard.blade.php:769

### [medium · accessibility · M] Chart values are reachable only by mouse hover and the Y axis has no scale

Every number in the revenue chart lives in SVG `<title>` elements (lines 1195, 1208), which render as a hover tooltip on desktop and are simply unreachable on the phones and tablets this app targets. The `<svg>` itself (line 1164) has no `role="img"` or `aria-label`, so screen readers get nothing. The four gridlines (lines 1166-1172) carry no value labels, and the only scale reference is 'Kunlik maksimum' rendered at `text-[10px] text-content/20` in the bottom-right corner (lines 1227-1229) - effectively invisible. As a result the chart communicates only relative shape, not amounts. Day labels at `font-size="9"` in a 700-unit viewBox (line 1220) render at roughly 4-5px on a 360px phone, i.e. unreadable.

**Рекомендация.** Add `role="img"` plus an `aria-label` summarising the month total to the `<svg>`, and render `<text>` value labels at x=0 on the 50% and 100% gridlines using `formatSum()`. For touch, either wrap the chart in `overflow-x-auto` with a `min-w-[700px]` inner div (the table idiom already used on this page) so labels keep their real size, or add a `wire:click`/Alpine tap handler on each bar that shows the selected day's totals in a caption line under the chart.

**Код:** resources/views/livewire/pages/admin/dashboard.blade.php:1164, resources/views/livewire/pages/admin/dashboard.blade.php:1195, resources/views/livewire/pages/admin/dashboard.blade.php:1227

### [medium · i18n · S] Product sales table mixes Russian 'сум' rows with Uzbek 'so'm' total in the same table

Row amounts render `$order->formattedTotal` (line 803), and that accessor hardcodes `' сум'` (app/Models/Order.php:51). The footer total on the very next lines uses `$this->formatSum()` (line 811) which goes through `__('common.currency')` = 'so'm' for uz and 'kaa'. So on the default Uzbek UI the same table shows '120 000 сум' per row and '360 000 so'm' in the total row - two languages, two currency spellings, one table.

**Рекомендация.** Replace line 803 with `{{ $this->formatSum((int) $order->total_price) }}` so every amount on the page goes through the single `formatSum()` helper (lines 559-562). Longer term the model accessors (`Order::formattedTotal`, `Appointment::formattedPrice`, `formattedDebt`) should use `__('common.currency')` too.

**Код:** resources/views/livewire/pages/admin/dashboard.blade.php:803, resources/views/livewire/pages/admin/dashboard.blade.php:811, resources/views/livewire/pages/admin/dashboard.blade.php:559

### [medium · accessibility · S] Tab buttons have no focus state and sit well under the recommended touch target size

The Kun/Oy tab buttons (lines 582-601) carry no `focus:` or `focus-visible:` classes at all, so keyboard users get only the browser default outline (frequently suppressed by the reset) while the date input right next to them has a proper `focus:ring-1 focus:ring-brass` (line 609) - the page contradicts itself. Their `px-4 py-1.5 text-xs` box is about 28px tall, well below the ~44px thumb target these front-desk phones need; the 'Barcha qarzlar' / 'Barcha savdolar' links (lines 704, 769) are bare `text-xs` links with no padding at all, making them equally hard to hit.

**Рекомендация.** Add `focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-brass` and bump to `px-5 py-2.5` (or `min-h-11`) on both tab buttons, and turn the two 'all …' links into padded pills (`rounded-lg px-3 py-2`) so they meet the same target size as the rest of the app's actionable elements.

**Код:** resources/views/livewire/pages/admin/dashboard.blade.php:582, resources/views/livewire/pages/admin/dashboard.blade.php:609, resources/views/livewire/pages/admin/dashboard.blade.php:704

### [low · consistency · S] The day and month payroll tables show the same concept with different columns, labels and totals

The two barber tables are near-identical in purpose but diverge in ways that make period-over-period comparison harder: the day table has a 'Bekor qilingan' column and buries the percent as a sub-line under the salary figure (lines 833, 928), while the month table drops cancellations and promotes the percent to its own 'Maosh %' column (lines 1249, 1326). The day column header reuses `dashboard.appointments_day` ('Kunlik yozuvlar', line 832) inside a table that is already scoped to the day, whereas the month table uses the cleaner `col_appointments` ('Yozuvlar', line 1246). The month footer carries a company profit row (lines 1364-1375) that the day footer omits entirely (lines 947-957), even though the day table's 'Qoldiq' total is exactly that number under a different name.

**Рекомендация.** Align both tables on one column set and order, use `dashboard.col_appointments` for the count header in both, put the percent in the same position in both, and either add the profit row to the day footer or drop it from the month footer and rely on the KPI card. Since the two `<tr>` bodies are already almost identical, extract them into a shared Blade partial/component under resources/views/components to keep them from drifting again.

**Код:** resources/views/livewire/pages/admin/dashboard.blade.php:832, resources/views/livewire/pages/admin/dashboard.blade.php:1246, resources/views/livewire/pages/admin/dashboard.blade.php:1364

**Сильные стороны области:**
- Proactive data-integrity banners: `brokenOperationsCount` / `monthlyBrokenOperationsCount` surface operations whose cash/card split or debt does not reconcile with the price, with a count and a plain-language instruction on where to fix them (dashboard.blade.php:680-690 and 1064-1074). Most CRMs let these rot silently - this pattern belongs on the appointments and orders pages too.
- Deactivated barbers are kept in the payroll tables with an 'Ishdan bo'shagan' badge instead of being filtered out (dashboard.blade.php:854-858, 1268-1272, logic at 243-264), so deactivating a barber cannot silently inflate company profit. Excellent, deliberate handling of a subtle accounting trap.
- Consistent money presentation: a single `formatSum()` helper (dashboard.blade.php:559-562), `tabular-nums` on every figure so columns align, conditional success/danger coloring driven by the value's sign (e.g. 1091-1117), and `<tfoot>` totals on both tables that reconcile with the KPI cards above them (947-957, 1354-1363).

---

## Область: appointments

### [critical · feedback · S] Client picker keeps the previously selected client_id when the search text is edited

`selectClient()` sets both `client_id` and `clientSearch`, but nothing resets `client_id` when the text box changes — there is no `updatedClientSearch()` hook. The x-search-select input is bound with `wire:model.live.debounce.300ms` to `clientSearch` only. So: admin picks "Ali", then clears the box and types "Bek" to correct the mistake, sees Bek in the dropdown, but hits Save without clicking the row — the visit is silently written to Ali. There is also no chip/checkmark showing which client is actually bound, and no way to clear a selection back to a walk-in (no-client) visit once one has been picked. Because debts, SMS reminders and the client card all hang off this id, a wrong binding sends the reminder to the wrong phone and puts the debt on the wrong person, with nothing on screen to reveal it.

**Рекомендация.** Add `public function updatedClientSearch(): void { $this->client_id = null; }` so the bound id can never outlive the text it came from, and render the confirmed selection as a chip under the field (`@if ($client_id)` … client name + an X button calling a `clearClient()` action that resets both `client_id` and `clientSearch`). Reuse the same badge styling already used for payment-type badges in the table.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:163

### [high · feedback · S] Save button has no loading/disabled state — a double tap creates a duplicate paid visit

The modal's submit button is a plain `<button type="submit">` with no `wire:loading.attr="disabled"` and no spinner, while `save()` does a full validate + `DB::transaction` with `lockForUpdate` and a `services()->sync()`. On a front-desk phone with a slow connection the button gives zero acknowledgement, so staff tap it again; Livewire pools both calls and `save()` runs twice with `editingId === null`, producing two identical appointments — which then double-count in the cash register and in the barber's payroll. The same problem applies to the day-navigation buttons `prevDay`/`nextDay`, which also show nothing while the request is in flight, so repeated taps skip past the intended day.

**Рекомендация.** Copy the house pattern from the orders modal verbatim: `wire:loading.attr="disabled" wire:target="save"` plus the inline `animate-spin` SVG and `disabled:cursor-not-allowed disabled:opacity-50` on the submit button (orders.blade.php:559-562). Add `wire:loading.attr="disabled" wire:target="prevDay,nextDay"` to the two day arrows.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:927

### [high · clarity · M] Auto-filled end time falls outside the time-slot list, so the End select renders empty

`timeSlots()` builds options from 00:00 to 23:00 stepping by `$timeStep`, which defaults to 60. `updatedFormStartTime()` auto-fills `form_end_time` as start + `service->duration_minutes`. Seeded durations include 45 minutes ("Мужская стрижка", "Чистка лица" — the most common services), so a 10:00 start produces 10:45, which is not among the 60-minute options. The `<select wire:model.live="form_end_time">` therefore falls back to the "— tanlang —" placeholder while the server still holds "10:45": the operator sees an apparently empty required field on the core create path, re-picks 11:00 by hand, and the service duration is silently lost. Secondary effects of the same list: it starts at midnight (24-96 options to scroll past on a phone before reaching working hours) and stops at 23:00, so no appointment can end after 23:00.

**Рекомендация.** Make the slot list contain whatever `form_start_time`/`form_end_time` currently hold: in `timeSlots()`, merge the two current values into the generated array and re-sort (`unique`+`sort`), and also unset the computed in `updatedFormStartTime()` so the new value is offered. Generate slots from the shop's opening hour rather than 00:00, and extend the upper bound to 23:45 so late finishes are selectable.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:218

### [high · convenience · S] Day view can only be stepped one day at a time — no date picker, no "Today"

The date bar offers only prev/next arrows. `setDate(string $date)` exists in the component but is never wired to anything in the template, so there is no way to jump to a date: checking next Saturday's book is 4-10 taps and the same number of full server round-trips, and returning to today from a week out is another 7. Nothing on the bar tells the operator whether the day on screen is today, yesterday or next month — only the weekday and formatted date are shown — so it is easy to create an appointment on the wrong day after browsing. The dashboard already solves exactly this with a bound date input.

**Рекомендация.** Add `<input type="date" wire:model.live="date" class="… dark:[color-scheme:dark]">` between the arrows, matching dashboard.blade.php:605-609, and a "Bugun" button calling `setDate(now()->toDateString())` that is highlighted (`bg-brass text-black`) while `$date === today`. Note that `setDate` must keep the existing `form_date` sync it already does.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:238

### [high · feedback · M] Blocked delete fails silently, and no action anywhere confirms it succeeded

`delete()` refuses to remove an appointment that has debt repayments and reports it via `$this->addError('selectedServices', …)`. That error bag is only rendered inside the modal, which is closed when deleting from the table row. The operator confirms the `wire:confirm` dialog, the row stays put, and absolutely nothing explains why — the natural next move is to keep retrying or to assume the app is broken. The positive side is equally silent: `save()`, `markConfirmed()`, `markCompleted()`, `markCancelled()` all just re-render, so after saving an edit there is no confirmation that anything was written, even though the app already has a toast idiom.

**Рекомендация.** Give the page a dedicated banner slot above the table driven by a public `$flash` message (or `dispatch()` + `x-on:…window` exactly as settings.blade.php:56 and :68-74 do), set it in `delete()` for the blocked case using the existing `appointments.err_delete_has_payments` string and in `save()`/`mark*()` for success. Render it with the same `border-danger/20 bg-danger/10` / `border-success/20 bg-success/10` pill classes already used in the modal.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:567

### [high · feedback · S] Cancel and Complete are single unconfirmed taps with no way back

`markCancelled` and `markCompleted` fire on one tap of a 32px icon button sitting immediately next to each other, with no `wire:confirm` — while the far less consequential Delete does have one. Worse, both are one-way in the UI: once a row is Completed or Cancelled the `@if/@elseif` chain renders no status buttons at all, so a mis-tap cannot be undone (`markConfirmed()` exists but is only reachable while the status is Pending, and `save()` deliberately preserves the existing status on edit). Completing a visit is also what books its money into the cash register and the barber's payroll, so an accidental tap has financial consequences the front desk cannot reverse themselves.

**Рекомендация.** Add `wire:confirm` to `markCancelled` (and to `markCompleted`) using new keys in lang/{uz,ru,kaa}/appointments.php, following the `appointments.delete_confirm` precedent. Add an @else branch to the status chain that renders a "restore" button calling `markConfirmed({{ $appointment->id }})` with `title="{{ __('common.confirm') }}"` for Completed and Cancelled rows.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:1056

### [medium · performance-ux · S] Money and service fields use wire:model.live with no debounce — a round trip per keystroke

`cash_amount`, `card_amount`, `debt_amount` and every per-service `amount` input are bound with bare `wire:model.live`. Typing a six-digit sum like 150000 fires six full component round trips, each of which re-renders the entire modal (services list, totals, debt panel) and, for the service rows, runs `updatedSelectedServices` → `fillServiceAmount` → an extra `Barber::with('services')->find()` query. On a front-desk phone this shows up as laggy typing and dropped digits in the fields that decide the day's cash. The app already knows better: the clients search and the shared search-select component both debounce at 300ms.

**Рекомендация.** Switch the amount inputs to `wire:model.live.debounce.500ms` (or `wire:model.blur`, since the running total only needs to be right when the field is left) — the price is recalculated again in `save()` anyway, so nothing depends on per-keystroke updates. Keep `.live` only on the selects (`service_id`, `payment_type`, `barber_id`) where a change genuinely needs an immediate server response.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:845

### [medium · responsive · M] Service rows in the modal do not stack on phones — the service select collapses to ~60px

Each service row is a single `flex items-center gap-3` line holding: a 20px index, the service `<select>` (`min-w-0 flex-1`), a fixed `w-36` (144px) amount input, and a 32px delete button, inside a modal that has `px-6` padding and `p-4` outer inset. On a 375px phone that leaves roughly 60px for the service name select — the very control that must show "Мужская стрижка (45 daq)". The duplicate marker span squeezes it further. The modal itself does not scroll horizontally (only the appointments table does), so the content is simply crushed.

**Рекомендация.** Make the row responsive: `flex flex-col gap-2 sm:flex-row sm:items-center` on the row div, `w-full sm:w-36` on the amount wrapper, and move the delete button onto the amount line with `self-end sm:self-auto`. This mirrors how the cart rows in the orders modal keep a `min-w-0 flex-1` text block separate from a `shrink-0` control cluster.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:844

### [medium · i18n · S] Two currency spellings inside the same table cell — hardcoded Cyrillic "сум" next to translated "soʻm"

The Total line renders `$appointment->formattedPrice` and the debt badge renders `$appointment->formattedDebt`; both accessors hardcode the Russian "сум" (Appointment.php:59 and :66). The per-service amounts and the cash/card split lines directly above and below them use `__('common.currency')`, which is "soʻm" in the default uz locale and "сум" only in ru. The result on the default UI is a single cell reading "45 000 soʻm … Jami: 45 000 сум … Qarz: 20 000 сум" — mixed scripts in one glance, on the column staff use to read money.

**Рекомендация.** Either drop the suffix from the accessors and append `{{ __('common.currency') }}` in the template like the neighbouring lines do, or have the accessors return `number_format(...).' '.__('common.currency')`. The same hardcoded suffix exists in Barber::$formattedPrice, so fix them together.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:1006

### [medium · accessibility · S] Icon-only buttons inconsistently labelled, and 32px tap targets are below thumb size

The status buttons carry `title` attributes, but the day-navigation arrows, the modal close button, the per-row service remove button, and the row Edit and Delete buttons are bare SVGs with no `title` and no `aria-label` — on a screen reader they announce as unnamed buttons, and for a new front-desk hire the pencil and trash glyphs sitting side by side are the only distinguishing cue. All row action buttons are `h-8 w-8` (32px) and spaced `gap-1.5` (6px), which is under the ~44px thumb target guidance for the very pair (Complete / Cancel) that has irreversible consequences.

**Рекомендация.** Add `title` + `aria-label` from the existing lang keys to every icon-only button (`common.edit`, `common.delete`, `common.close`, `common.back`/`common.next` for the arrows, `appointments.add_service` counterpart for remove). Bump the row action buttons to `h-10 w-10` with `gap-2` at the default breakpoint and add a visible focus ring (`focus-visible:ring-1 focus-visible:ring-brass/40`) — the inputs already have one, the buttons do not.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:1073

### [medium · clarity · S] The day view shows no summary — no count, no day total, no outstanding debt

The header prints a static subtitle and the date bar prints only the weekday and date. Nothing tells the operator how many visits the day holds, how many are still unconfirmed, what the day is worth, or how much of it went out on debt — all of which they must currently count by eye down a table where the money column is hidden below md. The SMS history page already puts live counters in exactly this subtitle position, so the idiom exists.

**Рекомендация.** Add a small computed summary over the already-loaded `$this->appointments` collection (no extra queries: count, `sum('price')`, `sum(fn ($a) => $a->outstandingDebt)`, and a pending count) and render it as a compact strip beside the date — either in the `<p class="mt-1 text-sm text-content/40">` subtitle like sms/history.blade.php:167-170, or as chips inside the date bar. Add the new keys to all three lang files.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:596

### [medium · convenience · S] New-appointment form starts empty even when the context already knows the answers

`openCreate()` calls `resetForm()`, which nulls `barber_id` and both time fields. If the admin is already filtered to one barber via `barberFilter` — the normal way to work through one master's book — that choice is thrown away and must be re-picked in the modal. There is also no default start time (so the first field the user must touch is a 24-option select), no autofocus on any field when the modal opens, and no service row pre-added, so creating the most common visit (one service, current barber, next round hour) takes noticeably more taps than the data justifies.

**Рекомендация.** In `openCreate()`, seed `$this->barber_id = $this->barberFilter` when a filter is active, seed `form_start_time` with the next slot boundary from `now()` rounded to `$timeStep`, and call `addService()` once so the first row is ready. Add `x-init="$nextTick(() => $el.querySelector('input,select')?.focus())"` on the modal panel to put the cursor in the client search on open.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:266

**Сильные стороны области:**
- Security-aware Livewire discipline worth copying to every other page: role and identity live in `#[Locked]` properties plus a session-read `abortIfBarber()`/`sessionBarberId()` pair, so the barber filter cannot be defeated from the client, and the sort column is whitelisted before it reaches the query (appointments.blade.php:79-95, 115-118, 192-197).
- Duplicate-service prevention is done at three levels right where the mistake happens: already-chosen services are `@disabled` in the other rows' selects, a duplicated row gets a red tint plus an inline "takror" marker, and `save()` still re-checks server-side (appointments.blade.php:810-843, 449-455).
- The day query is genuinely tuned for the data it renders: `with(['client','barber','services'])` plus `withSum('debtPayments as debt_paid_total')` feeds the `debtPaid`/`outstandingDebt` accessors without an N+1, and it uses a half-open `whereBetween` on `starts_at` instead of `whereDate` so the index is usable (appointments.blade.php:176-186).

---

## Область: kassa

### [critical · pagination-scale · M] Both debt tables load every outstanding debt with ->get(), no pagination, search or filter

appointmentDebts() and orderDebts() each run ->with([...])->get() over every record with an unpaid balance, and the Blade renders all of them in two full tables. Unpaid debts are never written off, so this set only grows: after a couple of years a barbershop accumulates hundreds of stale debts (plus their eager-loaded client/barber/services rows) that must all be rendered on every Livewire round-trip. Worse for daily work: there is no way to search by client name/phone and no date filter, so the front desk answering "does Aziz still owe us?" has to scroll two tables visually. A public $tab = 'all' property exists but is never read anywhere in the file - the intended filtering was clearly planned and never wired up.

**Рекомендация.** Adopt the sms/history pattern: `use WithPagination;` and `->paginate(25)` in both computed properties, render `{{ $this->appointmentDebts->links() }}` / `{{ $this->orderDebts->links() }}` under each table. Add a `public string $search = ''` bound with `wire:model.live.debounce.300ms` (same idiom as clients.blade.php:134) filtering on `whereHas('client', fn ($q) => $q->where('name','like',...)->orWhere('phone','like',...))`, plus `updatedSearch() { $this->resetPage(); }`. Either wire the dead $tab into an all/appointments/orders filter or delete it. Keep grandTotal/totalAppointmentDebt/totalOrderDebt as SQL aggregates (a `sum` over the scoped query) so the summary cards stay correct once the lists are paginated.

**Код:** resources/views/livewire/pages/admin/debts.blade.php:43

### [high · clarity · M] "Revenue of the day" on the cash register sums the full sale price, including money never received

todayTotal() returns `$this->orders->sum('total_price')`, displayed under the label orders.revenue_day ("Kunlik tushum"). The app's own domain layer says the opposite: HasCashRegisterAmounts::$receivedAmount is documented as the single source of truth for the till and explicitly excludes debt, and the dashboard uses `sum(fn ($o) => $o->receivedAmount)` for product revenue. So a 100 000 sale entirely on debt makes the orders page show revenue 100 000 and debt 100 000 at the same time - the cashier cannot tell what is actually in the drawer, and the same day shows a different number on the dashboard. The mirror problem: debt repayments accepted today (which the dashboard does count, dashboard.blade.php:181) never appear on the orders page at all, so closing the day from this screen misses collected cash.

**Рекомендация.** Change todayTotal() to `$this->orders->sum(fn (Order $o) => $o->receivedAmount)` so the green card means money in the till, and add a third small card or a sub-line for the gross figure (`sum('total_price')`) labelled separately, e.g. `orders.turnover_day` vs `orders.revenue_day`. Add a card for debt repayments received on the selected date (`DebtPayment::whereDate('paid_at', $this->date)->sum('amount')`), matching how dashboard.blade.php:181 already builds the day's cash. Add the new keys to lang/{uz,ru,kaa}/orders.php.

**Код:** resources/views/livewire/pages/admin/orders.blade.php:93

### [high · feedback · S] Blocked order deletion fails silently - the error is rendered only inside the closed create form

deleteOrder() refuses to delete a sale that already has debt repayments and reports it with `$this->addError('cart', __('orders.err_delete_has_payments'))`. But the only place that renders `@error('cart')` sits inside the `@if ($showForm)` block, which is false in the normal case (list view, form closed). The admin confirms the wire:confirm dialog, the row stays put, and absolutely nothing is shown - the button looks broken. This is exactly the case where the operator most needs the explanation, because the reason (repayments booked into other days' tills) is not guessable.

**Рекомендация.** Move the destructive-action error out of the form: render a page-level alert right under the header, e.g. `@error('cart') <p class="mb-4 rounded-xl bg-danger/10 px-4 py-3 text-xs font-bold text-danger">{{ $message }}</p> @enderror` above the orders table (or use a dedicated `deleteError` bag key so it cannot be cleared by form validation). While there, add `wire:loading.attr="disabled" wire:target="deleteOrder"` to the trash button so a double tap on a slow phone connection cannot fire twice.

**Код:** resources/views/livewire/pages/admin/orders.blade.php:267

### [high · feedback · S] Debt repayment silently clamps overpayment and gives no confirmation of what was recorded

recordPayment() records `min($requested, $fresh->outstandingDebt)` and then just closes the modal. If the cashier types 500 000 for a 50 000 debt (fat finger, or the client hands over a bigger note), 50 000 is stored and the modal disappears with no message - the operator has no way to know the entered amount was not what got booked. The `max="{{ $maxPay }}"` attribute on the input is inert here because the button is a `wire:click` on a plain button, not a form submit, so the browser never validates it. There is also no success feedback at all for a correct payment: the row silently vanishes from a possibly long table, which on a money screen reads as "did that work?".

**Рекомендация.** Validate before writing: in recordPayment(), if `$requested > $fresh->outstandingDebt` call `$this->addError('payAmount', __('debts.err_amount_exceeds', ['max' => ...]))` and return instead of clamping; the existing `@error('payAmount')` slot already renders next to the field. On success, flash a confirmation the same component can show, e.g. `session()->flash('kassaMessage', __('debts.payment_recorded', ['amount' => ...]))` plus a dismissible banner above the summary cards (there is no toast component in this app, so a flash banner is the lightest consistent option). Add the new keys to lang/{uz,ru,kaa}/debts.php.

**Код:** resources/views/livewire/pages/admin/debts.blade.php:143

### [high · convenience · S] Pay-debt modal is not a form: no Enter to submit, no autofocus, deferred model needs an extra tap

The amount field uses `wire:model` (deferred) and the confirm button is a `type="button"` with `wire:click`, so pressing Enter after typing the amount does nothing - the cashier must tap the amount field, type, then aim at the button. Nothing focuses the amount input when the modal opens, so on a tablet the keyboard does not come up either. Every other form in this app (appointments.blade.php:651, clients.blade.php:150, orders.blade.php:367, products.blade.php:126, ...) uses `<form wire:submit="...">`, so this money-critical flow is also the only one that breaks the muscle memory of keyboard submit.

**Рекомендация.** Wrap the modal body in `<form wire:submit="{{ $payAction }}">` and make the confirm button `type="submit"` (keep `wire:loading.attr="disabled" wire:target="{{ $payAction }}"`). Focus the amount on open with the Alpine already present in the modal: `x-init="$nextTick(() => $refs.amount.focus())"` on the wrapper plus `x-ref="amount"` on the input. Since the payment amount is prefilled with the full outstanding balance, Enter then becomes a genuine one-keystroke "paid in full".

**Код:** resources/views/livewire/pages/admin/debts.blade.php:225

### [medium · performance-ux · S] Money inputs in the sale form round-trip to the server on every keystroke and re-render the whole page

cash_amount, card_amount and debt_amount all use `wire:model.live` with no debounce. Typing "150000" fires six requests, and each one re-renders the entire component: the availableProducts query, the whole product tile grid, the day's orders table with its items/products, and every computed total. On a phone at the front desk this shows up as laggy, jumping input. None of these three values is used for any live conditional in the template (only payment_type is, and that one legitimately needs .live) - they are only read in save().

**Рекомендация.** Switch the three amount inputs to `wire:model.blur` (or `wire:model.live.debounce.500ms` if you want the split hint to update as you type), matching the debounce idiom already used at clients.blade.php:134 and in x-search-select. Leave `wire:model.live` on payment_type, which drives the conditional cash/card block.

**Код:** resources/views/livewire/pages/admin/orders.blade.php:459

### [medium · i18n · M] Money in table rows renders hardcoded Russian "сум" next to __('common.currency') in the headers

The row amounts use the model accessors formattedTotal / formattedDebt / formattedPrice, which append a hardcoded ' сум' (Order.php:52 and :59, Appointment.php:59 and :66), while the headline cards on the same screens use `__('common.currency')` - 'soʻm' in uz, 'sum' in kaa. On the default Uzbek UI the day card reads "250 000 soʻm" and the row right under it reads "250 000 сум": two languages, two spellings, on the one screen where numbers must be unambiguous. Same mix on the debts tables.

**Рекомендация.** Make the accessors locale-aware - replace `.' сум'` with `.' '.__('common.currency')` in Order, Appointment and any sibling model - or, if you want to keep models presentation-free, drop the accessors in these two views and format inline like the cards already do: `{{ number_format($order->total_price, 0, '.', ' ') }} {{ __('common.currency') }}`. Grep for `' сум'` to catch the rest at once.

**Код:** resources/views/livewire/pages/admin/orders.blade.php:612

### [medium · responsive · S] Five-column money tables have no column-hiding breakpoints, unlike every other table in the app

Both the orders table and the two debt tables are 5 columns of `px-6 py-4` inside a single `overflow-x-auto`. On a phone this means the operator scrolls horizontally to reach the amount and the action button - and on the debts page the action button ("Pay") is the last column, so the single most important control on the screen is off-screen by default. Seven other pages (sms/history.blade.php:245, appointments, clients, products, services, barbers, telegram/linked) already hide secondary columns with `hidden sm:table-cell`.

**Рекомендация.** Apply the existing pattern: on the orders table hide the composition column (`hidden md:table-cell` on both `<th>` and `<td>`, and surface item names as a small line under the client on mobile); on the debts tables hide the barber/services and products columns on small screens. Reduce padding to `px-4 sm:px-6` so the amount and action columns fit a 390px viewport without horizontal scrolling.

**Код:** resources/views/livewire/pages/admin/debts.blade.php:286

### [medium · clarity · S] Debt rows never show how much has already been repaid, though the data is loaded

Both debt queries add `withSum('debtPayments as debt_paid_total', 'amount')`, but the template never renders it. The amount column shows the original price (grey) over the remaining balance (red) under a single header "Summa / Qarz", so for a partially repaid debt the operator sees 200 000 / 50 000 and cannot tell whether 150 000 was paid, whether the debt was only ever 50 000, or what the grey number even means. Since repayments are separate records that land in other days' tills, "already paid" is exactly the number the front desk needs when a client disputes a balance.

**Рекомендация.** Add a third line in the amount cell using the value already fetched, e.g. `@if ($order->debtPaid > 0)<div class="text-[10px] text-success">{{ __('debts.paid_so_far') }}: {{ number_format($order->debtPaid, 0, '.', ' ') }} {{ __('common.currency') }}</div>@endif`, and split the ambiguous header into two explicit labels (`debts.amount` / `debts.remaining_debt`, the latter already exists in the lang files). Add debts.paid_so_far to lang/{uz,ru,kaa}/debts.php.

**Код:** resources/views/livewire/pages/admin/debts.blade.php:56

### [medium · accessibility · S] Icon-only controls on both money screens have no accessible name or tooltip

The order delete button is a bare trash SVG, the cart quantity minus/plus and remove-item buttons are bare SVGs, and the pay-modal close button is a bare X. None has aria-label, title or sr-only text, so screen readers announce "button" and new staff get no hover hint on what a red trash icon in the amount row will do (here, it deletes a sale and returns stock). The debt toggle correctly uses role="switch" + aria-checked but its `<label>` has no `for`/id pairing, so the switch is announced without its name.

**Рекомендация.** Add `aria-label` + `title` from the lang files to each icon-only button: `title="{{ __('common.delete') }}" aria-label="{{ __('common.delete') }}"` on the trash button, `__('common.decrease')`/`__('common.increase')`/`__('common.remove')` on the cart controls, `__('common.close')` on the modal X. Give the debt switch `aria-label="{{ __('orders.on_debt') }}"`. Also add a visible focus ring (`focus-visible:ring-1 focus-visible:ring-brass`) - these buttons currently rely on hover only.

**Код:** resources/views/livewire/pages/admin/orders.blade.php:634

### [medium · convenience · S] Product picker renders every in-stock product as a tile with no search

availableProducts() returns every active product with stock > 0, ordered by name, and the template renders each as a tile in a two-column grid. With a realistic retail range (waxes, shampoos, pomades, blades - easily 40+ SKUs) that is a long scroll on a phone before you can add a single item, the list is re-queried and re-rendered on every component update, and there is no way to type the first letters of a product. The client field on the same form already has exactly the right control (x-search-select with 300ms debounce).

**Рекомендация.** Add a `public string $productSearch = ''` bound with `wire:model.live.debounce.300ms` above the tile grid and filter in the computed property (`->when($this->productSearch, fn ($q) => $q->where('name','like',"%{$this->productSearch}%"))`), keeping the tiles for touch. Add the placeholder to lang/{uz,ru,kaa}/orders.php.

**Код:** resources/views/livewire/pages/admin/orders.blade.php:81

### [medium · convenience · M] On phones the cart, total and Save button sit below the entire product grid with no feedback when an item is added

The sale form is `grid lg:grid-cols-2`, so below the lg breakpoint the product picker column stacks above the cart column. Tapping a product tile fires a server round-trip whose only visible result (the cart line and the running total) is rendered several screens further down - the operator gets no confirmation that the tap registered and must scroll back and forth to build a multi-item sale, then scroll again to reach Save.

**Рекомендация.** Keep the running total in view on small screens: render a compact sticky bar at the bottom of the form on mobile (`sticky bottom-0 lg:static` on the total/save row, with the existing brass total styling) showing item count + cartTotal and the submit button. Optionally show an item-count badge next to the __('orders.cart') heading so the tap has an immediate visible effect at the top of the column.

**Код:** resources/views/livewire/pages/admin/orders.blade.php:367

**Сильные стороны области:**
- Destructive actions on the till are genuinely guarded: the delete button carries wire:confirm with a message that explains the stock return (orders.blade.php:634), and deleteOrder() refuses outright when the sale already has debt repayments so a closed day cannot silently shift (orders.blade.php:266). Worth copying to any other page that deletes money records.
- The pay-debt modal has excellent defaults: it prefills the full outstanding balance (debts.blade.php:84), offers a one-tap "pay in full" shortcut (debts.blade.php:232), closes on Escape and on backdrop click (debts.blade.php:204), and the confirm button disables itself with wire:loading.attr. That default-plus-shortcut combination should be the template for every amount input in the app.
- Header figures show the outstanding balance rather than the debt originally issued, with the reasoning documented in the computed property (orders.blade.php:100), and the debt card links straight to the debts page (orders.blade.php:349). The debts page also has a proper illustrated empty state instead of a blank table (debts.blade.php:388).

---

## Область: clients

### [critical · pagination-scale · S] Client list is silently truncated to 100 rows with no pagination

`clients()` ends with `->limit(100)->get()` ordered by `id` desc, and the template renders that collection directly. Nothing in the UI says the list is cut off — the header even prints the real total (`{{ $this->totalClients }}`), so once the base passes 100 clients the page shows "Total: 3 480" above a table of 100 rows. Client 101+ is reachable only by guessing a search term. The counter also never reflects the filter: searching "Ali" still shows the global total, so staff cannot tell how many matches they got. This is the one page whose data grows forever, and it is the only list in the app that is capped instead of paginated.

**Рекомендация.** Adopt the house pattern from `admin/sms/history.blade.php`: `use Livewire\WithPagination;`, replace `->limit(100)->get()` with `->paginate(25)`, add `public function updatedSearch(): void { $this->resetPage(); }`, and render `{{ $this->clients->links() }}` in a `mt-6` wrapper after the table card. Show the filtered count next to the total (`$this->clients->total()`) when `$search !== ''`. Note `lang/ru/pagination.php` exists but `lang/uz` and `lang/kaa` have none — add them so the pager labels are not Russian on the default UI.

**Код:** resources/views/livewire/pages/admin/clients.blade.php:41

### [critical · pagination-scale · M] Client card loads the entire visit, order and SMS history — all three tabs rendered at once

`appointmentHistory()`, `orderHistory()` and `smsHistory()` all end in `->get()` with no limit, and the three tab panels are Alpine `x-show` blocks, so every row of all three histories is queried, hydrated and written into the HTML on every render — even the two hidden tabs. A loyal client after a few years easily has 100+ appointments plus an SMS row per reminder/retention/broadcast, so opening a regular client's card becomes a multi-hundred-row page while the front desk waits. `orderHistory` also eager-loads `items.product` for every order ever placed. Tab counts are computed from these fully loaded collections (`$this->appointmentHistory->count()`), so the cost cannot be avoided by a lazy tab.

**Рекомендация.** Make the tab server-side (`public string $tab = 'appointments'` with `wire:click="$set('tab','orders')"` and `@if ($tab === ...)` around each panel) so only the visible history is queried and rendered. Bound each query with a `public int $historyLimit = 20` plus a "Show more" button (`wire:click="$set('historyLimit', $historyLimit + 20)"`), or use `->paginate(20, pageName: 'appointmentsPage')` per list with `WithPagination`. Get the tab badges from `$client->loadCount(['appointments','orders','smsMessages'])` instead of counting the loaded collections.

**Код:** resources/views/livewire/pages/admin/clients/show.blade.php:121

### [high · clarity · S] Phone search does not match the phone format the UI itself displays

The search builds `%{$search}%` and matches it against the raw `phone` column, which `Client::normalizePhone()` stores as bare digits (`998901234567`). Every screen shows the number as `+998 90 123 45 67` (`formattedPhone`). So a receptionist who reads the number off the client's card or a Telegram message and types `+998 90`, `90 123` or `90 123 45 67` gets "no clients found", even though the client exists. Only an unformatted digit run works, which is not what anyone sees anywhere in the app. On the page whose whole job is finding a client fast, this is a daily dead end.

**Рекомендация.** Normalize the term before querying: `$digits = preg_replace('/\D+/', '', $this->search);` then `$q->where('name','like',$term)->when($digits !== '', fn($q) => $q->orWhere('phone','like','%'.$digits.'%'))`. Strip a leading `998` from `$digits` too so `+998 90…` and `90…` both hit. The same fix applies to the client pickers in `admin/orders.blade.php:70` and `admin/appointments.blade.php`, so extract it as a small `scopeSearch` on `Client`.

**Код:** resources/views/livewire/pages/admin/clients.blade.php:36

### [high · i18n · M] Two different currency words on the same client card (soʻm vs сум)

The metric cards use the component's `money()` helper, which appends `__('common.currency')` — `soʻm` on the default uz UI, `sum` on kaa. The history tables below print `$appointment->formattedPrice`, `$order->formattedTotal` and `$order->formattedDebt`, whose model property hooks hardcode `.' сум'` in Russian. The result on the default locale is one screen where "Sarflangan: 1 250 000 soʻm" sits directly above a table of "350 000 сум" rows. Same mismatch on the Karakalpak UI.

**Рекомендация.** Replace the hardcoded `' сум'` in `App\Models\Appointment` (formattedPrice/formattedDebt), `App\Models\Order` (formattedTotal/formattedDebt) and any sibling accessor with `.' '.__('common.currency')`, matching the `money()` helper at show.blade.php:158. Grep for `сум` across `app/Models` and fix them together in one pass so all money rendering goes through the lang file.

**Код:** resources/views/livewire/pages/admin/clients/show.blade.php:299

### [high · convenience · M] Client card is read-only: no edit and no "book this client" action

The card shows name, phone, birth date, Telegram status and full history, but the only writable thing on it is the notes box. To fix a typo in the name or add a birth date the user must click back to the list, find the client again (in a 100-row truncated table, possibly re-typing the search), then click the pencil. And the single most frequent front-desk action after opening a client card — creating an appointment or an order for that client — has no entry point either; the user goes to Appointments and re-searches the same client in the picker. The header block at lines 170-183 has an empty right-hand side that is clearly meant for actions.

**Рекомендация.** Put an action row in the header: an "Edit" toggle that reveals the same three-field form used in `clients.blade.php:150-181` (name/phone/birth_date, same validation and duplicate-phone check) inline on the card, plus links to `route('admin.appointments', ['client' => $client->id])` and the orders page so the client is preselected. Reuse the existing notes-saved badge pattern for the save confirmation.

**Код:** resources/views/livewire/pages/admin/clients/show.blade.php:170

### [medium · feedback · S] No loading or disabled state on any button, and no confirmation after create/edit/delete

Neither clients page uses `wire:loading` at all. The client save button, the notes save button and the search box give zero visual response between click and re-render; on a phone over mobile data that reads as "nothing happened" and invites a second tap. The rest of the app does this properly — `admin/orders.blade.php:559`, `auth/login.blade.php:106`, `admin/sms/settings.blade.php:139` and `admin/telegram/broadcast.blade.php:136` all use `wire:loading.attr="disabled"` with a spinner swap. Additionally, `save()` and `delete()` just close the form / drop the row with no success message, while the notes form on the very same client card does show a "Saqlandi" badge — so the app already has the pattern and these flows skip it.

**Рекомендация.** Add `wire:loading.attr="disabled" wire:target="save"` plus the spinner-svg swap used at orders.blade.php:561 to both submit buttons (clients.blade.php:176, show.blade.php:252), and a `wire:loading.delay wire:target="search"` spinner inside the search field like booking.blade.php:503. After `save()`/`delete()` dispatch an event and reuse the Alpine badge from show.blade.php:236-245 for a short-lived success confirmation.

**Код:** resources/views/livewire/pages/admin/clients.blade.php:176

### [medium · accessibility · S] Icon-only edit/delete buttons and the search field have no accessible name

The two 36x36 action buttons per row contain only an inline SVG — no `title`, no `aria-label`, no visually hidden text. A pencil and a trash can are guessable, but there is no hover tooltip, and screen readers announce them as unnamed buttons; the delete button sits 8px from edit and is destructive. `admin/appointments.blade.php:1052-1068` already does this right with `title="{{ __('...') }}"` on every icon-only action. The search input likewise has no `<label>` or `aria-label`, only a placeholder that disappears once typing starts.

**Рекомендация.** Add `title="{{ __('common.edit') }}" aria-label="{{ __('common.edit') }}"` and `title="{{ __('common.delete') }}" aria-label="{{ __('common.delete') }}"` to the two buttons, matching appointments.blade.php:1052. Add `aria-label="{{ __('clients.search_placeholder') }}"` to the search input. Also give both buttons a visible focus ring (`focus:ring-1 focus:ring-brass/20`) like the inputs already have, since keyboard focus is currently invisible on them.

**Код:** resources/views/livewire/pages/admin/clients.blade.php:218

### [medium · i18n · S] Field validation errors render in Russian on the uz and kaa UI

`name`, `phone` and `birth_date` rely on framework validation messages (`#[Validate('required|string|max:255')]`) and the template prints `$message` under each field. `lang/uz` and `lang/kaa` contain no `validation.php`, and `config/app.php:83` sets `fallback_locale` to `ru`, so a barber working in the Uzbek UI who submits an empty name sees Uzbek labels with a Russian sentence underneath ("Поле имя обязательно для заполнения"). The two hand-written errors on this same form (`clients.err_phone_format`, `clients.err_duplicate`) are correctly translated, which makes the mix more jarring.

**Рекомендация.** Either copy `lang/ru/validation.php` to `lang/uz` and `lang/kaa` and translate the handful of rules actually used (required, string, max, date, numeric, unique), or attach per-field messages via a `messages()` method on the component pointing at `clients.*` keys, mirroring how `err_phone_format` is already localized.

**Код:** resources/views/livewire/pages/admin/clients.blade.php:15

### [medium · convenience · S] Create form does not focus the name field, and opens far from the button on mobile

`openCreate()` flips `$showForm` and the form appears above the table, but no field receives focus — on a tablet the user must tap "Add", then tap the name input, then the keyboard opens. Adding a walk-in client is one of the highest-frequency actions on this screen. On a phone the three-column form (`sm:grid-cols-3`) stacks to three full-height rows, pushing the Save button well below the fold with no indication it is there.

**Рекомендация.** Add `x-data x-init="$nextTick(() => $el.focus())"` (or plain `autofocus`) to the name input at line 154 so the keyboard is ready immediately, and `x-on:keydown.escape.window="$wire.cancel()"` on the form card so Escape closes it. Enter already submits via `wire:submit`, so this completes a keyboard-only add flow.

**Код:** resources/views/livewire/pages/admin/clients.blade.php:154

### [low · consistency · S] Three date formats coexist on one client card

The profile facts block renders dates through `Client::formatLocalizedDate()` ("16 iyun 2026", month name in the active language), while every history table right below prints `->format('d.m.Y H:i')` ("16.06.2026 14:00"). The client list uses the numeric form too. A user scanning the card reads "Last visit: 16 iyun 2026" and then has to mentally map it onto "16.06.2026 14:20" in the appointments tab to find the same row.

**Рекомендация.** Pick one presentation for history rows and use it everywhere: either drop `formatLocalizedDate` from the facts block in favour of `d.m.Y`, or add a small `formatLocalizedDateTime()` next to `Client::formatLocalizedDate()` (Client.php:55) and use it in the three history tables and in the list's `last_appointment` column.

**Код:** resources/views/livewire/pages/admin/clients/show.blade.php:296

### [low · responsive · S] Fixed-width search box and Add button share a non-wrapping row

The header's right-hand group is `flex items-center gap-3` with no `flex-wrap`, holding a `w-64` (256px) search field and the Add button (~120px). The outer container wraps, but this inner group cannot, so on a 320-360px phone the pair is squeezed below the input's comfortable width or pushes the header wider than the viewport. Front-desk staff use this page on phones, and search is the primary control here — it should get the full width when there is no room to share.

**Рекомендация.** Make the group `flex w-full flex-wrap items-center gap-3 sm:w-auto`, and change the input to `w-full sm:w-64` inside a `flex-1` wrapper so search takes the full line on phones with the Add button dropping beneath it.

**Код:** resources/views/livewire/pages/admin/clients.blade.php:131

**Сильные стороны области:**
- Eager loading done right on the list: `->with(['latestAppointment.barber'])` combined with the `latestOfMany('starts_at')` HasOne on the model (Client.php:119) renders a per-row "last appointment + barber" column with no N+1 — the pattern to copy anywhere a list needs a per-row "latest" fact (resources/views/livewire/pages/admin/clients.blade.php:32).
- Localized destructive confirmation: the delete button uses `wire:confirm` with the client's name interpolated into the translated string, so the prompt names exactly what is about to disappear instead of a generic "Are you sure?" (resources/views/livewire/pages/admin/clients.blade.php:223).
- Lightweight, dependency-free success feedback: `saveNotes()` dispatches `notes-saved` and a small Alpine wrapper shows a self-clearing badge for 2.5s — a clean toast substitute worth reusing on every other form in the app (resources/views/livewire/pages/admin/clients/show.blade.php:236).

---

## Область: catalog

### ❌ ОТКЛОНЕНО ФАКТ-ЧЕКОМ · [critical · feedback · S] Deleting a service silently destroys its entire appointment history

The services table renders an appointments count for each row (services.blade.php:243, from withCount('appointments') at :31-35), yet the delete button next to it fires a plain wire:confirm with only the service name (services.blade.php:250-251). appointments.service_id is declared ->constrained()->cascadeOnDelete() (database/migrations/2026_05_04_144823_create_appointments_table.php:18) and appointment_service cascades too, so removing a service that has been used for years deletes every appointment booked with it - revenue history, salary basis, client history - irreversibly, from a single tap by a non-technical admin. The same shape exists one page over: specializations.blade.php:151 shows barbers_count while :160-161 deletes with a generic confirm, quietly nulling specialization_id on every barber (nullOnDelete in 2026_05_04_150904_alter_barbers_add_specialization_id.php:15).

**Рекомендация.** In services.blade.php, wrap the delete button in @if ($service->appointments_count === 0) and render a disabled button with a title explaining that used services can only be deactivated; steer the admin to the existing is_active toggle, which already hides the service from booking via Service::active(). Add a services.delete_blocked lang key. Where deletion is still allowed, interpolate the count into the confirm string (extend services.delete_confirm with :count) and do the same for specializations.delete_confirm using barbers_count.

**Код:** resources/views/livewire/pages/admin/services.blade.php:250

### [high · pagination-scale · M] Products list is unbounded with no search, filter, or pagination

products() does Product::orderBy('name')->get() (products.blade.php:32) and the whole result set is rendered in one table (:181). Inventory is the one catalog table that genuinely grows - shampoos, waxes, oils, brand variants accumulate over years - and the page even advertises the total in the header (products.blade.php:112) while giving no way to find a single item among them. Worse, every stock +/- tap calls adjustStock and re-renders the entire list (products.blade.php:80-86, :188-201), so on a front-desk tablet the cost of one restock click grows linearly with catalogue size. The app already has both halves of the fix: ->paginate(25) in sms/history.blade.php and a debounced search box in clients.blade.php:134.

**Рекомендация.** Add `use Livewire\WithPagination;`, a `public string $search = ''` bound with wire:model.live.debounce.300ms in the header next to the Add button (copy the search markup from clients.blade.php:134), and a `public string $stockFilter = ''` for all / low stock / out of stock. Change products() to ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))->orderBy('name')->paginate(25), add updatedSearch() { $this->resetPage(); }, and render {{ $this->products->links() }} under the table like sms/history does. Add wire:key="product-{{ $product->id }}" to each <tr> so stock updates patch a single row.

**Код:** resources/views/livewire/pages/admin/products.blade.php:32

### [high · clarity · M] Barber work-schedule grid is dead UI - 14 inputs that change nothing

The barber form devotes its largest block to a 7-day schedule with two free-text time inputs per day (barbers.blade.php:36-44 defaults, :238-251 grid). The value is stored and cast (Barber::casts 'schedule' => 'array'), but nothing in the application ever reads it: appointment time slots are generated unconditionally from 00:00 to 23:00 (appointments.blade.php:217-229), and a repo-wide search for 'schedule' outside barbers.blade.php and the Barber model turns up only an unrelated Telegram method name. Staff therefore maintain opening hours that never prevent a booking outside them, which is worse than having no field at all. The inputs are also type="text" with only 'required|array' validation (barbers.blade.php:35), so "9", "9-00" or an empty string save happily and there is no way to express a day off.

**Рекомендация.** Decide and commit in one of two directions. Cheapest: remove the schedule block from the form and let Barber::$schedule keep its default, so nobody maintains fiction. Better: keep it, validate it ('schedule.*.0' => ['nullable', 'date_format:H:i'] plus the same for .1, with a day-off checkbox that nulls the pair), and filter appointments.blade.php timeSlots by the selected barber's schedule for the chosen date so out-of-hours slots simply do not appear. Either way switch the inputs to type="time" so phone keyboards show the time picker.

**Код:** resources/views/livewire/pages/admin/barbers.blade.php:238

### [high · clarity · S] Barber default price can never be entered, yet the form promises it

The component declares and validates `public ?int $price` (barbers.blade.php:26-27), loads it on edit (:87), and persists it (:106), but the template contains no input for it. Its only appearance is as a placeholder inside the per-service price rows: placeholder="{{ $price ?? '-' }}" (barbers.blade.php:217), which for every newly created barber is null and renders a bare dash. Directly under it the hint barbers.default_price_hint tells the admin "leave empty - the default price is used" (:223). Since resetForm() nulls $price (:150) and no field can set it, Barber::priceForService() (app/Models/Barber.php:60-70) falls through to null for every barber created through this screen: leave a service price blank and the barber has no price at all, while the UI insists a default exists.

**Рекомендация.** Add a 'default price' number input to the basic-info grid next to salary_percent, bound to wire:model="price" with the ({{ __('common.currency') }}) suffix used elsewhere, and a new barbers.default_price lang key in uz/ru/kaa. Keep the existing placeholder wiring - once $price is settable the per-service placeholders become meaningful and the hint text becomes true.

**Код:** resources/views/livewire/pages/admin/barbers.blade.php:217

### [high · feedback · S] No four catalog forms show progress, disable submit, or confirm success

All four save buttons are plain submits with no wire:loading state and no wire:loading.attr="disabled" (barbers.blade.php:287-290, services.blade.php:192-195, products.blade.php:159-162, specializations.blade.php:124-127), and no page dispatches a success message - after save() the panel simply disappears (e.g. products.blade.php:75-77). On a front-desk phone the result of a tap is indistinguishable from a dropped tap. It is worst on barbers, where save() uploads and processes a photo through Spatie MediaLibrary (barbers.blade.php:119-123) and can take seconds: a second tap creates a duplicate barber. The app already has the right idiom on the sales screen - orders.blade.php:559-561 disables the submit and swaps in an animated spinner with wire:target="save".

**Рекомендация.** Copy the orders.blade.php:559-561 button verbatim into all four forms: wire:loading.attr="disabled" wire:target="save" plus the inline spinner SVG and a wire:loading label using the existing common.saving key. Since the card collapses on success, also scroll-anchor confirmation by keeping the header count/list visible, or dispatch a short-lived flash the layout can render.

**Код:** resources/views/livewire/pages/admin/barbers.blade.php:287

### [high · convenience · M] Stock can only be changed one unit at a time, with no feedback per tap

The only inline way to change inventory is a -1 / +1 stepper (products.blade.php:188-201 calling adjustStock at :80-86). Receiving a delivery of 30 bottles means 30 server round-trips, or opening the edit form and overwriting the absolute value (products.blade.php:142) - which silently discards any sale that decremented stock in the meantime via orders.blade.php:251. Neither stepper button has a loading or disabled state, so on a slow mobile connection the natural reaction (tap again) double-applies the change, and because the whole list re-renders there is no per-row confirmation that the number moved.

**Рекомендация.** Keep the +/- buttons for the common single-unit correction but add an inline receive control: a small number input plus an 'add' button per row calling adjustStock($product->id, $qty), or a second pair of -10/+10 buttons. Add wire:loading.attr="disabled" wire:target="adjustStock({{ $product->id }})" to each stepper and wire:key on the row so only that row patches. Add products.receive / products.stock_hint lang keys rather than hardcoding labels.

**Код:** resources/views/livewire/pages/admin/products.blade.php:188

### [medium · accessibility · S] Every icon-only button in the catalog lacks an accessible name

The edit and delete controls on all four tables are bare 9x9 buttons containing only an SVG, with no aria-label, no title, and no sr-only text: barbers.blade.php:349 and :353, services.blade.php:246 and :250, products.blade.php:222 and :226, specializations.blade.php:156 and :160. The same applies to the stock steppers (products.blade.php:188, :198) and to the service icon picker, where each of the six choices is an unlabeled button (services.blade.php:161) - a screen reader announces six identical 'button's and a sighted user gets no tooltip explaining what 'swatch' or 'beaker' means. The buttons also rely on hover-only colour change with no focus-visible ring, so keyboard tabbing gives no indication of position.

**Рекомендация.** Add title and aria-label to every icon button using the existing common.edit / common.delete keys, e.g. aria-label="{{ __('common.edit') }}" title="{{ __('common.edit') }}", and products-specific keys for the steppers. For the icon picker add aria-pressed="{{ $icon === $iconKey ? 'true' : 'false' }}" and a title with a new services.icon_* label per key. Append focus-visible:ring-1 focus-visible:ring-brass/40 to the shared button classes so keyboard focus is visible in both themes.

**Код:** resources/views/livewire/pages/admin/products.blade.php:222

### [medium · accessibility · M] Form labels are not associated with their inputs; the photo uploader has no name at all

Every field in all four forms uses a floating <label> element with no for attribute and an input with no id - barbers.blade.php:181-183, services.blade.php:140-142, products.blade.php:129-131, specializations.blade.php:107-109 and all their siblings. On a touch device tapping the label does nothing (a real annoyance on the small number inputs), and assistive tech reads the inputs as unlabeled. The barber photo uploader is the extreme case: an invisible file input stretched over the drop zone (barbers.blade.php:275, class="absolute inset-0 cursor-pointer opacity-0") with no label, no aria-label, and no focus outline, so it is unreachable in any meaningful way by keyboard and anonymous to a screen reader.

**Рекомендация.** Give each input an id and point the label at it with for="..." (or wrap the input inside its <label>, which needs no ids and is the smaller diff). For the photo field, add aria-label="{{ __('barbers.photo') }}" to the file input and a focus-within:border-brass/40 class on the surrounding drop zone so keyboard focus is visible.

**Код:** resources/views/livewire/pages/admin/barbers.blade.php:275

### [medium · i18n · S] Product prices print hardcoded Russian currency on the Uzbek and Karakalpak UI

The price cell renders $product->formattedPrice (products.blade.php:205), and that accessor hardcodes Cyrillic 'сум' (app/Models/Product.php:36: number_format(...).' сум'). Everywhere else in the app the currency comes from the lang files - __('common.currency') resolves to 'soʻm' in uz - as in barbers.blade.php:209, products.blade.php:135 (the form's own price label), orders.blade.php:414, debts.blade.php:181. The result is one screen mixing 'Sotuv narxi (soʻm)' in the form with '35 000 сум' in the table two rows below.

**Рекомендация.** Change Product::$formattedPrice to number_format((int) $this->selling_price, 0, '.', ' ').' '.__('common.currency'), matching the helper already used in dashboard.blade.php:561 and clients/show.blade.php:158. No template change needed; check for other models with the same literal while you are there.

**Код:** resources/views/livewire/pages/admin/products.blade.php:205

### [medium · pagination-scale · S] No way to hide deactivated barbers or services from the catalog tables

barbers() and services() fetch every row regardless of is_active (barbers.blade.php:57-60, services.blade.php:31-35), and neither page has a search box or a status filter. The is_active flag exists precisely so that people who left and services no longer offered stop appearing in booking flows, but here they stay mixed into the working list forever, marked only by a grey pill (barbers.blade.php:341, services.blade.php:237). After a few years of staff turnover an admin editing a price has to scan past a growing tail of dead rows, and there is no way to search by name. The clients page already solved this with a debounced search input (clients.blade.php:134).

**Рекомендация.** Add `public bool $showInactive = false;` to both components with a small toggle in the header (reuse the existing peer/sr-only switch markup) and apply ->when(! $this->showInactive, fn ($q) => $q->where('is_active', true)) in the computed query, defaulting to active-only. On barbers, also add the clients.blade.php:134 search input bound with wire:model.live.debounce.300ms filtering on name.

**Код:** resources/views/livewire/pages/admin/services.blade.php:31

### [medium · responsive · S] Specializations table forces horizontal scrolling on phones

The other three catalog tables hide secondary columns below the sm breakpoint - barbers.blade.php:302-304, services.blade.php:208-209, products.blade.php:176 all use `hidden ... sm:table-cell` and surface the key value inline under the name instead (barbers.blade.php:322). Specializations does not: name, description, barbers count and actions all render at every width (specializations.blade.php:138-141, :147-152), and the description cell has no truncate or max-width (:148), so a one-line description pushes the actions column off-screen and the front-desk user has to scroll the wrapper sideways to reach edit/delete.

**Рекомендация.** Mirror the house pattern: add `hidden sm:table-cell` to the description <th> and <td>, and render the description as a muted second line under the name inside a `sm:hidden` div, exactly as barbers.blade.php:322 does for specialization. Add `max-w-xs truncate` to the desktop description cell.

**Код:** resources/views/livewire/pages/admin/specializations.blade.php:148

### [low · consistency · S] The four near-identical CRUD pages disagree on labels, keys, and header stats

Same layout, four different conventions. Submit labels: products uses common.save / common.add (products.blade.php:161) while the other three use common.save_changes plus a page-specific create key (barbers.blade.php:289, services.blade.php:194, specializations.blade.php:126). Status wording: services defines and uses its own services.active / services.inactive (services.blade.php:183, :234, :239) duplicating common.active / common.inactive used by barbers and products (barbers.blade.php:338, products.blade.php:211). Header stats: only products shows a total count (products.blade.php:112, matching clients.blade.php:127); barbers, services and specializations show none. Field labels: barbers calls its text field common.name (barbers.blade.php:181) while products and specializations call the same concept common.title_field (products.blade.php:129, specializations.blade.php:107). Individually trivial, collectively it makes the admin section feel assembled by four people.

**Рекомендация.** Standardise on common.save_changes for edit and common.add for create across all four; delete services.active/services.inactive and point the template at common.active/common.inactive; add the products.blade.php:112 total-count subtitle line to the other three using their withCount data (barbers/services/specializations already have cheap counts available); and pick one of common.name vs common.title_field per entity type and use it in both the form label and the table header.

**Код:** resources/views/livewire/pages/admin/products.blade.php:161

**Вердикты факт-чека (отклонённые):**
- Deleting a service silently destroys its entire appointment history — The core mechanism claim is wrong for the current schema. `appointments.service_id` no longer exists: migration database/migrations/2026_05_15_133704_create_appointment_service_table.php up() lines 18-21 does `$table->dropForeign(['service_id']); $table->dropColumn('service_id');`. Only its down() re-adds the column (line 27), and no later migration restores it (the 11 migrations touching `appointments` add price/note, payment_type, cash/card amounts, debt_amount, salary_percent, indexes). A repo-wide grep for `service_id` under app/ returns no matches. So deleting a Service cascades only the `appointment_service` pivot rows, not the appointments: the appointment row survives with its own `price` column (2026_05_04_162402_add_price_and_note_to_appointments_table.php), which app/Models/Appointment.php:59,75 uses as the revenue/total amount. Real loss is the per-service line-item breakdown (pivot `amount`, shown at appointments.blade.php:995 and used by clients/show.blade.php:109-111), not "every appointment booked with it - revenue history, salary basis, client history". Everything else cited checks out: services.blade.php:31-35 withCount('appointments'), :243 renders the count, :250-251 plain wire:confirm with only the name; specializations.blade.php:151 shows barbers_count, :160-161 generic confirm, and 2026_05_04_150904_alter_barbers_add_specialization_id.php:15-16 is nullOnDelete.

**Сильные стороны области:**
- Every destructive action already goes through wire:confirm with the record name interpolated from a lang file - barbers.blade.php:354, services.blade.php:251, products.blade.php:227, specializations.blade.php:161 - so no delete is a bare one-tap accident and the prompt is localised. This is the right baseline for every other page in the app.
- The four pages share a genuine design system rather than one-off markup: identical card shell, table chrome, status pill, and peer/sr-only toggle switch, plus the mobile pattern of hiding a column with `hidden sm:table-cell` and re-surfacing its value as a muted second line under the primary cell (barbers.blade.php:322, services.blade.php:224). Worth applying wherever a table still shows every column on phones.
- Stock levels use colour thresholds that read at a glance without a legend - green above 5, brass at 1-5, red at 0 (products.blade.php:192-197) - and the same thresholds are reused on the sales screen (orders.blade.php:385-387), so the signal means the same thing in both places. Every table also has a localised @forelse empty state (products.blade.php:236, services.blade.php:260, specializations.blade.php:170).

---

## Область: sms

### [critical · pagination-scale · M] SMS history has no search and no date filter — only reverse-chronological paging

The history query filters on exactly two things: send/delivery status and context. There is no way to search by client name or phone, and no date range. The page's entire purpose is auditing ("did client X actually get their reminder on Tuesday?", "why is this client complaining they got no SMS?"), and the only way to answer that is to click through 25-row pages in reverse chronological order. With daily reminders plus retention plus broadcasts, a single year is easily 20k+ rows / 800+ pages. The page works today and becomes unusable within months, which is exactly the degradation pattern to catch now. Every other list-shaped screen in the app already has at least one narrowing control (clients has a debounced search, orders and dashboard have date pickers).

**Рекомендация.** Add `public string $search = ''` and `public string $from = ''` / `public string $to = ''` to the component. Bind search with the house idiom `wire:model.live.debounce.300ms="search"` (copy the input markup from clients.blade.php:134) and apply `->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->where('phone', 'like', "%{$digits}%")->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$this->search}%"))))`. Add two `type="date"` inputs styled exactly like orders.blade.php:313 with `->when($this->from !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->from))` and the mirror for `to`. Add `updatedSearch()` / `updatedFrom()` / `updatedTo()` calling `$this->resetPage()`, following the existing `updatedStatus()` at history.blade.php:150.

**Код:** resources/views/livewire/pages/admin/sms/history.blade.php:46-56 (query: only status + context), :172-189 (only two selects in the header)

### [high · i18n · M] The one paginator in the app renders untranslated English in vendor gray, on every locale

`$this->messages->links()` falls through to Livewire's unpublished `tailwind.blade.php`, which emits `__('Showing')`, `__('to')`, `__('of')`, `__('results')` and `__('pagination.previous')`/`__('pagination.next')`. There are no `lang/*.json` files, so the first four render literally as English. `lang/uz/pagination.php` and `lang/kaa/pagination.php` do not exist, so those locales fall back to `app.fallback_locale = 'ru'` — and `lang/ru/pagination.php` still ships Laravel's untouched `'&laquo; Previous'` / `'Next &raquo;'`. Result: a Uzbek-speaking admin sees "Showing 1 to 25 of 340 results  « Previous  Next »" — the only English on an otherwise fully translated screen. The same vendor view also hardcodes `bg-white border-gray-300 text-gray-700 dark:bg-gray-800`, i.e. a white/gray control strip bolted under the app's translucent Paper-&-Brass card. Bonus fragility: `resources/css/app.css` registers an `@source` for Laravel's pagination views but not Livewire's — those gray classes only exist in the bundle because `deploy.sh` happens to run `npm run build` (line 61) before `artisan optimize:clear` (line 67), so the previous deploy's compiled views in `storage/framework/views` are still on disk. Reorder those steps and the paginator renders with no styling at all.

**Рекомендация.** Run `php artisan livewire:publish --pagination` to get `resources/views/vendor/livewire/tailwind.blade.php` (which is inside the existing `@source '../**/*.blade.php'`), then restyle it with the module's own tokens — `rounded-xl border border-content/[0.08] bg-content/[0.04] text-content` for buttons, `bg-brass text-on-brass` for the current page — and replace the six string calls with `__('common.pagination_showing')` etc., adding the keys to all three of lang/{uz,ru,kaa}/common.php. This fixes the language, the theme mismatch, and the `@source` fragility in one pass.

**Код:** resources/views/livewire/pages/admin/sms/history.blade.php:291 (`{{ $this->messages->links() }}`); lang/ru/pagination.php holds literal English and lang/uz, lang/kaa have no pagination.php

### [high · feedback · S] Zero loading indication on the only page that paginates and live-filters

There is not a single `wire:loading` in history.blade.php (verified by grep: 0 matches). Both filter selects are `wire:model.live`, and each change — plus every page click — round-trips a request that re-runs the 30-day metrics aggregation and two full-table COUNTs before anything repaints. On front-desk mobile data the screen simply sits there showing stale rows with no spinner, no dimming, no disabled control. Staff will re-click the filter or the page button, firing more of the same expensive requests. The module already contains the correct pattern one file over: the settings page's check button disables itself, swaps its label, and spins.

**Рекомендация.** Wrap the table card in `wire:loading.class="opacity-40 pointer-events-none" wire:target="status,context,gotoPage,nextPage,previousPage"` and drop the existing spinner SVG (verbatim from settings.blade.php:142) into the header row next to the selects with the same `wire:target` list. No new markup conventions needed.

**Код:** resources/views/livewire/pages/admin/sms/history.blade.php:173,180 (live selects), :251 (table body), :291 (pagination) — contrast the correct pattern at resources/views/livewire/pages/admin/sms/settings.blade.php:139-145

### [high · convenience · S] SMS balance — the number that decides whether reminders go out at all — is hidden behind a manual button

`balance` is only populated inside `check()`, and `check()` only fires on an explicit click. The balance row is additionally nested behind `@if ($checked)` and `@if ($connectionOk)`, so a normal visit to the SMS settings page shows no balance whatsoever. Nobody clicks a diagnostic button on a healthy-looking page. When the Eskiz balance hits zero, every reminder and retention SMS stops silently — the history page will fill with red "Xato" badges and nobody will connect the two. This is the single highest-consequence number in the whole module and it is opt-in.

**Рекомендация.** Add `wire:init="check"` to the status card's root div so the check runs automatically on load — the existing `wire:loading wire:target="check"` spinner at line 142 already covers the in-flight state, so no new UI is needed. Then surface the balance as a fourth tile in the history page's metric grid (history.blade.php:193) next to the cost estimates, so the person watching spend also sees the runway. Guard with a short `cache()->remember(..., now()->addMinutes(10), ...)` in the component so the Eskiz call doesn't fire on every render.

**Код:** resources/views/livewire/pages/admin/sms/settings.blade.php:78-83 (check() is the only writer of $balance), :121-136 (balance double-gated behind $checked and $connectionOk)

### [high · responsive · S] On phones the history table hides the timestamp — the one column staff need most

Both the type column and the date column are `hidden ... sm:table-cell`. On a phone at the front desk — the primary device for this app — the history shows recipient, message body and status, but nothing about *when* the SMS was sent. Every real question asked of this screen ("was the reminder sent before her appointment?", "did we spam him twice today?") needs the timestamp. The message column meanwhile has `max-w-md` with no truncation, so long broadcast bodies stretch rows to five or six lines and push the status badge off to the side of a horizontal scroll.

**Рекомендация.** Keep the column hidden but re-expose the value inside the recipient cell, mirroring the two-line composition already there: under the phone `<div>` at line 255 add `<div class="text-xs text-content/40 sm:hidden">{{ $message->created_at->format('d.m H:i') }}</div>`. Also add `line-clamp-2` to the message cell at line 257 so rows stay a predictable height.

**Код:** resources/views/livewire/pages/admin/sms/history.blade.php:245,247 (headers hidden below sm), :258,278 (cells hidden below sm), :255 (recipient cell where the date belongs), :257 (untruncated message)

### [medium · clarity · S] Metric cards and the 30-day chart silently ignore the active filters

The metrics query filters only on status and a date window — it never applies `$this->context` or `$this->status`. So selecting context = "Tarqatma" (broadcast) filters the table below while the three period cards and the chart above keep showing all-context totals, with no visual cue that they are unfiltered. An admin who selects "broadcast" and then reads "≈ 240 000 so'm" off the cost tile will report that as the cost of broadcasts. Given the cards sit directly above the filtered table and share its page, the assumption that they're linked is inevitable.

**Рекомендация.** Extract the two `->when()` clauses from `messages()` into a private `applyFilters(Builder $q)` helper and call it from both the `$sent30`/`$failed30` queries and `messages()`, so the whole page moves as one unit. If keeping the metrics global is deliberate, then say so — add `{{ __('sms.metrics_all_contexts') }}` as a caption under the card grid heading.

**Код:** resources/views/livewire/pages/admin/sms/history.blade.php:90-98 (metrics query has no context/status filter) vs :46-56 (table query does); cards render at :193-213

### [medium · clarity · S] Status dropdown flattens two unrelated axes into one undifferentiated list

The single status select mixes send status (`sent`, `failed` — did we hand it to Eskiz) with Eskiz delivery status (`delivered`, `undelivered`, `rejected`, `waiting` — did the handset get it). The component itself has to branch on the value to know which column to query. To a non-technical admin the options read as near-synonyms: "Yuborilgan" (sent) and "Yetkazildi" (delivered) are both green, both positive, and nothing in the dropdown explains that filtering by one is a different question from filtering by the other. The same ambiguity is repeated in the table, where a row can carry both a green "Yuborilgan" pill and a red "Rad etildi" pill with no explanation of how both can be true.

**Рекомендация.** Wrap the options in `<optgroup label="{{ __('sms.group_send_status') }}">` (sent/failed) and `<optgroup label="{{ __('sms.group_delivery_status') }}">` (the deliveryLabels loop), adding the two keys to all three lang files. In the table cell, prefix the delivery pill with a tiny static caption or a `title="{{ __('sms.delivery_status_hint') }}"` so the two-pill row is self-explaining.

**Код:** resources/views/livewire/pages/admin/sms/history.blade.php:48-52 (query branches on which column the value belongs to), :180-188 (flat option list), :262-275 (two pills stacked with no distinction)

### [medium · accessibility · S] Filter selects and settings toggles have no accessible name or visible focus state

The two history filter selects have no `<label>` and no `aria-label`; a screen reader announces two anonymous comboboxes. Worse, both settings toggles are `<input type="checkbox" class="peer sr-only">` inside a `<label>` that wraps only the decorative track `<div>` — the descriptive text lives in a sibling div outside the label, so the checkbox has no accessible name at all, just "checkbox, checked". These two toggles turn customer-facing SMS dispatch on and off. And because the real input is `sr-only` with no `peer-focus` styling, a keyboard user tabbing through the page gets no visible indication of which toggle is focused before pressing space — meaning it is possible to disable all appointment reminders without any on-screen confirmation of what you were about to hit.

**Рекомендация.** Add `aria-label="{{ __('sms.toggle_reminder_label') }}"` (and the retention equivalent) to the checkboxes, or give each descriptive `<p>` an `id` and point the `<label for="...">` at the input. Add `peer-focus-visible:ring-2 peer-focus-visible:ring-brass/40 peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-surface` to the track div so focus is visible. Add `aria-label="{{ __('sms.col_type') }}"` / `aria-label="{{ __('common.status') }}"` to the two history selects.

**Код:** resources/views/livewire/pages/admin/sms/settings.blade.php:178-181 and :188-191 (sr-only inputs, label wraps only the track, no focus ring); resources/views/livewire/pages/admin/sms/history.blade.php:173,180 (unlabeled selects)

### [medium · performance-ux · M] Metrics pull 30 days of rows into PHP and add two full-table COUNTs on every filter change and page click

`metrics()` runs `->get()` on all sent and all failed messages of the last 30 days, hydrates them into collections, then buckets them into days with PHP loops and runs three separate `filter()` passes for the today/week windows. Separately, `sentCount` and `failedCount` are unbounded `COUNT(*)` over the entire table just to render one subtitle line. All four queries re-execute on every status change, every context change, and every pagination click — the exact interactions this page is built around. The 30-day window is self-limiting, so this won't explode, but at a few thousand SMS per month it adds a visible pause to every click, and the all-time COUNTs get slower forever. Combined with finding #3 (no loading indicator) the page reads as broken rather than slow.

**Рекомендация.** Replace the two `->get()` calls with a single grouped aggregate: `->selectRaw('DATE(created_at) as day, status, COUNT(*) as c, SUM(COALESCE(parts,1)) as parts')->groupBy('day','status')`, then build the 30-day skeleton from that result set (tariff-by-prefix can be folded in with a second `groupBy` on a substring of `phone`). Drop `sentCount`/`failedCount` from the subtitle entirely — the 30-day cards immediately below already carry the sent/error numbers, so the all-time figures are duplicate information bought at the price of two full scans.

**Код:** resources/views/livewire/pages/admin/sms/history.blade.php:90-98 (two ->get() calls), :104-124 (PHP-side bucketing), :59-68 (unbounded COUNTs), :168-169 (their only consumer)

### [medium · clarity · M] 30-day chart has no axis labels and its numbers only exist in a hover tooltip that touch devices never fire

The chart renders 30 unlabeled bars in a 128px-tall strip. There is no x-axis, no y-axis, and no gridline, so a bar tells you nothing beyond relative height — you cannot tell which day a spike belongs to. The only per-day figures live in a native `title="..."` attribute, which is a desktop-mouse-hover affordance and produces nothing at all on the tablets and phones this app targets. On a 360px phone, 30 bars with `gap-1` leave roughly 8px per bar, so even the shape is hard to read. The app already solves this properly elsewhere: the dashboard's daily revenue chart draws real gridlines and prints day labels beneath the bars.

**Рекомендация.** Follow the dashboard idiom (dashboard.blade.php:1164-1213): render the chart as an inline `<svg>` with a slightly taller viewBox, keep the existing `<title>` children for desktop hover, and add `<text>` day labels for every 5th bar plus the same `stroke="var(--line)"` horizontal gridlines. For touch, wrap in `x-data="{ hover: null }"` and show the selected day's sent/failed counts in the card header — the module already uses this Alpine-in-the-card pattern on the settings page.

**Код:** resources/views/livewire/pages/admin/sms/history.blade.php:224-235 (bare bars, title-attribute-only data, no labels); compare resources/views/livewire/pages/admin/dashboard.blade.php:1164-1213

### [low · i18n · S] Hardcoded "API endpoint" label despite the translation key already existing

The settings status card prints the literal string `API endpoint` instead of calling `__()`, while `sms.api_endpoint` is already defined and translated in all three lang files (lang/uz/sms.php:13, and the ru/kaa equivalents). Every other row label in that same card goes through `__()`. It's a one-word slip, but it is the exact class of drift that makes a screen half-translated, and the fix is already sitting in the lang file unused.

**Рекомендация.** Change line 118 to `<span class="text-sm text-content/50">{{ __('sms.api_endpoint') }}</span>`.

**Код:** resources/views/livewire/pages/admin/sms/settings.blade.php:118 (hardcoded) vs :103,:114,:123,:132 (all use __())

### [low · clarity · S] Template page shows raw {time} placeholders with no rendered preview and no SMS length/cost hint

Each template body is printed verbatim, so staff read `...soat {time} da...` and have to mentally substitute. There's a "Variables: {time}" chip in the header, but no example of what the customer actually receives. And although the history page prominently prices SMS by `parts`, the templates page — the only place the message text is visible — shows no character count or segment count, so nobody can see that a Cyrillic Russian template crosses the 70-character boundary into a 2-part (double-cost) message while the Latin Uzbek one does not. That's directly actionable information for a read-only page whose only job is to inform.

**Рекомендация.** Under each template `<p>` add a muted line rendering the substituted text, e.g. `{{ str_replace('{time}', '14:30', $text) }}`, plus `{{ mb_strlen($text) }} {{ __('sms.chars') }} · ≈{{ $parts }} SMS` computed with the same segment logic SmsService already uses for `parts`. Keeps the page read-only, adds no controls, reuses the existing muted-caption styling.

**Код:** resources/views/livewire/pages/admin/sms/templates.blade.php:80-83 (placeholder chips), :95 (raw template text, no preview or length)

**Сильные стороны области:**
- history.blade.php is a genuinely good pagination skeleton and the right base to copy across the app: a `#[Computed]` query that eager-loads the relation (`->with('client')`, line 47) so the table has no N+1, `->paginate(25)` (line 55), `resetPage()` wired into every filter's `updated*` hook (lines 150-158), and a proper `@forelse` empty state with a translated message spanning the full table (lines 280-283). Everything is right except search and loading feedback.
- settings.blade.php:139-145 is the reference submit-button pattern: `wire:loading.attr="disabled"` scoped with `wire:target="check"`, an inline spinner SVG, a label that swaps between 'Check connection' and 'Checking…', and `@disabled(! $configured)` so the action can't be fired when it can't succeed. Every action button in the app should look like this one.
- settings.blade.php:149-158 shows an excellent zero-click settings pattern: each `updated*` hook persists immediately via `Setting::set()` and `$this->dispatch('toggle-saved')`, and a tiny Alpine wrapper shows a 'Saved' pill for 2.5s with a self-clearing timeout. No Save button, no ambiguity about whether the change stuck — worth reusing on every other toggle-style settings screen.

---

## Область: telegram

### [critical · pagination-scale · M] Linked page loads and renders every linked client and barber with ->get(), no pagination/search/filter

The `linked()` computed runs `Client::whereNotNull('telegram_chat_id')->get()` and `User::where('role', BARBER)->whereNotNull('telegram_chat_id')->get()`, hydrates every matching model, maps each into an array, concats and sorts in PHP, then the template renders every single row. The header count on line 61 (`$this->linked->count()`) is derived from the same fully-materialised collection, so it costs a second full pass rather than a COUNT query. Every client who ever taps /start in the bot lands in this list permanently, so it only ever grows — after a couple of years this is thousands of rows of HTML pushed to a front-desk phone on every page visit. There is also no search box and no client/barber filter, so finding one person to unlink means Ctrl+F or endless scrolling.

**Рекомендация.** Adopt the house pattern from `sms/history.blade.php`: `use WithPagination`, add `public string $search = ''` and `public string $type = ''` props, build the list as a paginated query (`->paginate(25)`) instead of `->get()` — e.g. select the same columns from `clients` and `users` and `unionAll()` them before paginating, or paginate the two queries behind the `type` filter. Add the search input using the exact markup from `clients.blade.php:134` (`wire:model.live.debounce.300ms="search"`), a `<select wire:model.live="type">` styled like the filters at `sms/history.blade.php:173-188`, `updatedSearch()`/`updatedType()` calling `$this->resetPage()`, replace the header count with two cheap `->count()` queries, and render `{{ $this->linked->links() }}` below the card like `sms/history.blade.php:291`.

**Код:** resources/views/livewire/pages/admin/telegram/linked.blade.php:17

### [high · feedback · S] Mass-send confirmation is a static string with no recipient count and no message preview

The single guard before blasting a message to every linked client is `wire:confirm="{{ __('telegram.broadcast_confirm') }}"` on the form. That lang string (lang/uz/telegram.php:24) is fixed text — it names no audience, no recipient count, and shows none of the message the admin is about to send to potentially thousands of people. The component already knows the exact number (`clientCount`/`barberCount` computeds power the badges, and `audience` is bound with `wire:model.live`), so the information exists and is simply not surfaced at the moment of decision. A typo, a half-finished sentence, or the wrong audience radio is unrecoverable: `SendTelegramBroadcast` dispatches immediately with no cancel path.

**Рекомендация.** Interpolate the blast radius into the confirmation: add a `#[Computed] recipientCount()` reusing the same audience switch, change the attribute to `wire:confirm="{{ __('telegram.broadcast_confirm', ['count' => $this->recipientCount, 'audience' => $audienceLabel]) }}"` and add the `:count`/`:audience` placeholders to the string in lang/uz, lang/ru and lang/kaa. Better still, replace the browser confirm with an Alpine review step in the same `x-data` wrapper already present on line 92: a panel that renders the typed message exactly as it will arrive plus "N recipients", with Cancel / Send buttons, so the admin proofreads before committing.

**Код:** resources/views/livewire/pages/admin/telegram/broadcast.blade.php:100

### [high · feedback · L] No broadcast history or delivery result — the only confirmation vanishes after 3.5 seconds

After `send()`, the only trace is an Alpine success banner that auto-hides via `setTimeout(() => sent = false, 3500)`. `SendTelegramBroadcast` then fires and forgets: it loops chat ids calling `TelegramService::sendMessage()`, which swallows every failure into `Log::error` (app/Services/TelegramService.php). Nothing is persisted. So an admin can never answer "did yesterday's promo actually go out?", "what text did we send?", or "how many failed because the client blocked the bot?" — they would have to read laravel.log. This is a striking inconsistency with the SMS side of the same app, where every message is a row with status and delivery_status and there is a full history screen with metrics.

**Рекомендация.** Mirror the SMS module: persist each broadcast (and optionally per-recipient outcome) the way `sms_messages` is persisted, have the job increment sent/failed counters from `sendMessage()`'s boolean return, and add a "Recent broadcasts" card under the form listing date, audience, message excerpt and sent/failed counts, using the same table + `->paginate(25)` + `->links()` idiom as `sms/history.blade.php:238-292`. Keep the green banner, but make the record the durable feedback.

**Код:** resources/views/livewire/pages/admin/telegram/broadcast.blade.php:93

### [medium · pagination-scale · M] Broadcast audience is all-or-nothing — it will eventually message every client who ever visited, including long-churned ones

`recipients()` selects every client with a non-null `telegram_chat_id` with no recency or activity condition. In year one that is a reasonable list; after several years the 'clients' audience is dominated by people who visited once and never returned. Blasting them is both wasted reach and the fastest way to get the bot blocked/reported, which silently degrades delivery for the clients who do matter. There is no way to narrow the send at all — no 'active in last N months', no per-barber audience.

**Рекомендация.** Add an optional recency filter next to the audience radios: a `<select wire:model.live="activeWithin">` (all / 3 / 6 / 12 months) styled like the filter selects at `sms/history.blade.php:173-179`, applied in `recipients()` with a `whereHas('appointments', fn ($q) => $q->where('starts_at', '>=', now()->subMonths($this->activeWithin)))`. Wire the same condition into the `clientCount` computed so the badge on the audience card always reflects the actual blast radius.

**Код:** resources/views/livewire/pages/admin/telegram/broadcast.blade.php:44

### [medium · consistency · S] Templates page teaches HTML formatting; the broadcast composer silently escapes it and never says so

`templates.blade.php:87` renders `telegram.html_note` — "Telegram HTML markup is supported: <b>, <i>, emoji and line breaks" — right above the template textareas, and the shipped DEFAULTS in app/Support/NotificationTemplates.php are full of `<b>` tags. The broadcast composer looks identical (same card, same textarea styling) but dispatches `e($this->message)`, so an admin who learned `<b>Aksiya!</b>` on the templates screen will send those literal characters to every client. The broadcast form carries no note about this at all, so the failure is only discovered after the message has already landed in thousands of chats.

**Рекомендация.** Either (a) add the same brass info-box used at `templates.blade.php:85-88` to the broadcast form with a new `telegram.broadcast_plain_note` key in lang/{uz,ru,kaa}, stating that formatting tags are not supported here; or (b) make the two screens actually consistent by replacing `e($this->message)` with a whitelist pass — `strip_tags($this->message, '<b><i><u><s><a><code>')` after escaping bare `&` — and reuse the existing `telegram.html_note` box on the broadcast page.

**Код:** resources/views/livewire/pages/admin/telegram/broadcast.blade.php:71

### [medium · feedback · S] Templates save button has no loading or disabled state, unlike every other submit button in the app

The save button is a bare `<button type="submit">` with no `wire:loading.attr="disabled"`, no `wire:target="save"`, and no spinner. The form posts five textareas of up to 2000 characters each and then writes five Setting rows, so on a front-desk tablet over mobile data there is a visible gap where nothing happens — the admin taps again and re-submits. Every comparable submit in this app does guard itself: `broadcast.blade.php:136`, `orders.blade.php:559`, `auth/login.blade.php:106`, `sms/settings.blade.php:139`. There is also no unsaved-changes cue across the five cards, so navigating away silently discards edits.

**Рекомендация.** Copy the button treatment from `broadcast.blade.php:136-141` verbatim: add `wire:loading.attr="disabled" wire:target="save"`, the `wire:loading.remove`/`wire:loading` spinner SVG pair, and `disabled:cursor-not-allowed disabled:opacity-50`. Add `wire:dirty.class="ring-2 ring-brass/40"` on the same button so unsaved edits are visible.

**Код:** resources/views/livewire/pages/admin/telegram/templates.blade.php:112

### [medium · convenience · M] Placeholder chips are decorative only, and a mistyped token ships broken text to real clients

Each template card lists its valid tokens as non-interactive `<code>` chips, so the admin must retype `{cancelled_for_client}`-style tokens by hand into the textarea — awkward on a tablet keyboard where `{` is two taps deep. `NotificationTemplates::render()` only `str_replace`s the names it is passed, so a typo like `{tme}` or a token borrowed from a different template (`{price}` inside the reminder, which is only given `{time}` and `{barber}`) is never substituted and is delivered literally to the client. Nothing on save catches this — validation is only `required|string|max:2000`.

**Рекомендация.** Make each chip a `<button type="button">` that inserts the token at the caret, using a small Alpine handler on the card (Alpine is already in this file at line 77) that manipulates `selectionStart` on the sibling textarea and dispatches `input` so Livewire picks it up. On top of that, validate in `save()`: `preg_match_all('/\{[a-z_]+\}/', ...)` against `$field['placeholders']` and surface unknown tokens with `$this->addError('values.'.$key, __('telegram.err_unknown_placeholder', ['token' => $bad]))` so the existing `@error('values.'.$key)` block on line 100 shows it inline.

**Код:** resources/views/livewire/pages/admin/telegram/templates.blade.php:103

### [medium · accessibility · S] Audience radios are sr-only with no focus indicator, making the choice invisible to keyboard users

The audience selector hides the real inputs with `class="sr-only"` and paints the state entirely on the wrapping `<label>` via the `@class` array on lines 111-115. That array styles only the checked/unchecked case — there is no focus rule anywhere. A keyboard user tabbing into the group therefore gets zero visible indication of which option has focus, on the one control that decides whether a message goes to 40 barbers or 4000 clients. The group also has no `role="radiogroup"` or accessible name; the `<label class="mb-2 block">` on line 103 is not associated with anything.

**Рекомендация.** Add `peer` to the `<input type="radio">` and append `'peer-focus-visible:ring-2 peer-focus-visible:ring-brass/40 peer-focus-visible:ring-offset-0'` to the `@class` array on the label. Give the grid wrapper on line 104 `role="radiogroup" aria-label="{{ __('telegram.audience_label') }}"`, and convert line 103 into a plain `<span>` since it is not a real label.

**Код:** resources/views/livewire/pages/admin/telegram/broadcast.blade.php:117

### [medium · feedback · S] Unlink action has no loading state and no success confirmation — the row just silently disappears

The unlink button fires `unlinkClient`/`unlinkBarber` with a `wire:confirm`, but carries no `wire:loading.attr="disabled"` and no `wire:target`. On a slow connection the admin confirms, sees nothing change for a second or two, and taps a second row. When the round trip completes the row simply vanishes with no toast, no undo and no explanation — inconsistent with the other two Telegram screens, which both show a green Alpine success banner (broadcast.blade.php:94-98, templates.blade.php:79-83). Unlinking is also destructive and irreversible from this UI: there is no way to re-link a chat id from the admin, the person has to press /start in the bot again.

**Рекомендация.** Add `wire:loading.attr="disabled" wire:target="unlinkClient({{ $row['id'] }})"` (and the barber variant) plus the spinner SVG used at `broadcast.blade.php:139`, and dispatch a `telegram-unlinked` event from both unlink methods rendered by the same `x-data` success banner markup copied from `broadcast.blade.php:92-98`. Mention in the confirm string that re-linking requires the user to press /start again.

**Код:** resources/views/livewire/pages/admin/telegram/linked.blade.php:91

### [medium · feedback · S] "Queued" success banner is shown even when nothing can deliver it — the page checks the bot token but never the queue

`mount()` guards on `config('nutgram.token')` and disables the button when the bot is unconfigured, which is good. But `send()` only dispatches a job onto `QUEUE_CONNECTION=database`, and the banner then asserts `telegram.queued` ("Broadcast queued · recipients: N") in success green. If the queue worker is not running — the single most common production failure on a single-node deploy — the job sits in the `jobs` table forever and the admin has explicit positive confirmation that the broadcast went out. Nothing on the page ever contradicts that.

**Рекомендация.** Extend the existing precondition pattern at lines 85-90: add a computed that reads `DB::table('jobs')->count()` (and oldest `available_at`) and, when a backlog is older than a couple of minutes, render the same danger banner markup with a new `telegram.queue_stalled` key in lang/{uz,ru,kaa}. Also reword `telegram.queued` to say delivery is in progress and may take a few minutes, rather than implying completion.

**Код:** resources/views/livewire/pages/admin/telegram/broadcast.blade.php:97

### [low · convenience · S] Broadcast textarea has no autofocus and no counter against the 2000-character limit

The composer is the only real input on the page, yet the textarea does not take focus on load, so every broadcast starts with an extra tap/click. `message` is validated `max:2000` but nothing in the UI communicates that budget — the admin discovers the limit only after writing a long announcement and pressing Send, and the error then appears at the bottom of the field after a round trip. There is also no Ctrl+Enter submit, although `wire:submit` on the form makes a keyboard path trivial.

**Рекомендация.** Add `autofocus` to the textarea and wrap the field in `x-data="{ n: @js(strlen($message)) }"` with `x-on:input="n = $event.target.value.length"`, rendering `<span x-text="n + '/2000'">` next to the label with a `:class` toggling `text-danger` past 2000 — the same Alpine-in-Blade style already used at line 92. Optionally add `x-on:keydown.ctrl.enter="$el.form.requestSubmit()"` on the textarea.

**Код:** resources/views/livewire/pages/admin/telegram/broadcast.blade.php:129

### [low · consistency · S] Linked table rows have no wire:key despite each row carrying a destructive id-bound action

The `@forelse` renders `<tr>` elements with no `wire:key`, while each row's button embeds a literal record id in `wire:click` and the client's name in `wire:confirm`. Livewire morphs unkeyed lists positionally, so after an unlink removes a row the DOM nodes shift and the diff has to rewrite those attributes in place — a class of bug the rest of this app deliberately avoids with keys on dynamic lists (booking.blade.php:324, orders.blade.php:411, appointments.blade.php:820). On a destructive, id-parameterised action the cost of getting it wrong is unlinking the wrong person.

**Рекомендация.** Add `wire:key="tg-{{ $row['type'] }}-{{ $row['id'] }}"` to the `<tr>`, matching the `wire:key` convention used elsewhere in the app.

**Код:** resources/views/livewire/pages/admin/telegram/linked.blade.php:78

**Сильные стороны области:**
- Blast-radius transparency on the audience selector: each option carries a live count badge from the clientCount/barberCount computeds (broadcast.blade.php:104-122), so the admin sees exactly how many people an option targets before choosing. Worth copying to any bulk action elsewhere in the app.
- Precondition banner plus a disabled action: the page detects a missing bot token in mount() and renders an explanatory danger banner while disabling the submit button (broadcast.blade.php:85-90 and :136), rather than accepting the click and failing silently in a log. This is the right template for SMS credentials, queue health and any other external-service dependency.
- Complete i18n discipline, including inside PHP logic: all three pages route every user-facing string through __() with keys in lang/{uz,ru,kaa}/telegram.php - not just in the Blade markup (linked.blade.php:69-73) but in the component's own data structures, where labels, hints and confirm strings are built via __() inside fields() (templates.blade.php:21-47). No hardcoded strings anywhere in the module.

---

## Область: public-booking

### [critical · clarity · M] Customer is quoted the barber's base price, not the price actually booked for the chosen service

Every price shown to the customer renders `$barber->formattedPrice`, which is the barber's generic `price` column. The appointment is created with a different value: `$barber->priceForService($service->id) ?? $barber->price ?? 0`, which prefers the per-service pivot price. So a barber who charges 50 000 base but 120 000 for colouring will show "50 000" on the barber card, in the confirmation summary and on the success screen, while the appointment (and later the cash register) records 120 000. Worse, the price block is wrapped in `@if ($this->selectedBarber?->price)` — a barber whose base price is null shows no price at all even though a per-service price exists, so the customer books blind and argues at the front desk.

**Рекомендация.** Add a computed `price` on the component that mirrors the booking logic: `#[Computed] public function price(): ?int { return $this->selectedBarber?->priceForService($this->serviceId); }` and render it everywhere instead of `formattedPrice`. On step 2 the service is already chosen, so eager-load the pivot (`Barber::active()->with(['specialization','media','services'])`) and show `$barber->priceForService($serviceId)` per card. Drop the `@if (...->price)` guard in favour of `@if ($this->price)` so per-service-only pricing still displays.

**Код:** resources/views/livewire/pages/booking.blade.php:261 (booked price) vs :377, :470-471, :575 (displayed price); app/Models/Barber.php:59-69, :88-90

### [critical · convenience · M] Time slots are hardcoded 00:00–23:00 and ignore the shop's configured working hours and the current time

`availableSlots()` loops `for ($h = 0; $h <= 23; $h++)`, so the public page offers 02:00, 04:00 and 05:00 as bookable slots. Meanwhile the admin settings screen already stores `work_start` / `work_end` (`Setting::get('work_start')`), and grepping the repo shows those two settings are read nowhere except the form that writes them — the booking page never consults them. There is also no filter against `now()`: at 18:00 a customer picking "Bugun" can still tap 09:00 and `confirm()` accepts it (`'date' => ['required','date']` has no `after_or_equal` rule), creating an appointment in the past that lands on today's admin list.

**Рекомендация.** Read the settings in `availableSlots()`: `$start = (int) substr(Setting::get('work_start', '09:00'), 0, 2); $end = (int) substr(Setting::get('work_end', '21:00'), 0, 2);` and loop that range. Then drop slots already gone: when `$this->date === today()`, skip any slot whose `Carbon::parse($this->date.' '.$slot['value'])->isPast()`. Add `'date' => ['required','date','after_or_equal:today']` and re-derive the slot list inside `confirm()` so a stale tab cannot post 03:00.

**Код:** resources/views/livewire/pages/booking.blade.php:127-136, :210-224; resources/views/livewire/pages/admin/settings.blade.php:30-31, :51-52

### [critical · feedback · M] Slots that are already taken are still clickable, and confirm() never re-checks availability — double bookings get created

`takenSlots` correctly computes clashes and the template paints those buttons red, but the button carries no `disabled` / `@disabled` attribute — it is a live `wire:click="selectTime('{{ $slot['value'] }}')"` that happily advances the customer to step 4. Nothing in `confirm()` re-runs the overlap check, so the customer fills in name and phone and gets the green "Ariza qabul qilindi!" screen for a slot that was visibly marked as busy. The same hole covers the ordinary race: two customers on two phones both pass step 3 with 14:00 free, both submit, and `Appointment::create()` writes both. The staff only discover it when two people show up.

**Рекомендация.** Add `@disabled($isTaken)` to the slot button (plus `aria-disabled="true"` and `disabled:cursor-not-allowed`) and guard the action server-side: `if (in_array($time, $this->takenSlots, true)) { return; }` at the top of `selectTime()`. Most importantly, re-run the overlap query inside `confirm()` just before `Appointment::create()` — `Appointment::where('barber_id',$barber->id)->active()->where('starts_at','<',$endsAt)->where('ends_at','>',$startsAt)->exists()` — and on a hit `$this->addError('time', __('booking.validation.slot_taken'))` plus `$this->step = 3` so the customer picks again instead of walking away with a phantom booking.

**Код:** resources/views/livewire/pages/booking.blade.php:437-446 (no disabled), :144-173 (takenSlots), :200-279 (confirm has no re-check)

### [high · convenience · S] Wizard steps have no URL state — the phone's back button leaves the site and a refresh wipes everything

`$step` is a plain public property with no `#[Url]` attribute, and the four `select*()` actions mutate it without touching history. On a phone the hardware/gesture back button is the reflex for "go back one step"; here it navigates the customer off blade-barbershop.uz entirely, losing service, barber, date and time. Reloading the page for any reason (bad signal, notification interrupt, accidental pull-to-refresh) drops the customer back to step 1 with an empty form. There is no visible in-page back affordance on step 1 either, so a customer who typed a phone number on step 4 and got interrupted starts over from scratch.

**Рекомендация.** Add Livewire URL binding so history works: `#[Url(as: 'step')] public int $step = 1;` and the same on `serviceId`, `barberId`, `date` and `time`. Livewire pushes state on change, so the native back button walks the wizard backwards and a refresh restores the selection. Keep the existing `back()` button as the visible affordance and have it call `$this->step--` as it does today.

**Код:** resources/views/livewire/pages/booking.blade.php:20-28 (no #[Url]), :175-198 (step mutations)

### [high · feedback · S] Tapping a service, barber, date or time gives no loading indication — on mobile data the taps look dead

Every step-advancing control is a bare `wire:click` with only a CSS `active:scale` press effect. `selectService`, `selectBarber` and `$set('date', ...)` all trigger a server roundtrip; the date change in particular re-runs `takenSlots`, which issues a fresh appointments query. On a 3G front-desk connection the customer taps "Soqol olish", sees nothing change for a second, and taps again — Livewire queues both, the step jumps, or the customer assumes the site is broken. The confirm button and the phone lookup both got proper `wire:loading` treatment, so the pattern is already in the file; it simply was not applied to steps 1–3.

**Рекомендация.** Reuse the existing spinner idiom from the phone field: wrap the step-1/2 lists in `<div wire:loading.class="pointer-events-none opacity-50" wire:target="selectService, selectBarber">`, and on the date strip add `wire:loading.class="animate-pulse" wire:target="date"` over the slot grid so the customer sees the slots refreshing. Add `wire:loading.attr="disabled"` to the slot buttons with `wire:target="selectTime"` to stop double taps.

**Код:** resources/views/livewire/pages/booking.blade.php:324-326, :363-364, :404-405, :437-438; compare the correct pattern at :503-505 and :540-545

### [high · i18n · S] Prices render the hardcoded Russian word "сум" on an otherwise Uzbek/Karakalpak page

`Barber::$formattedPrice` returns `number_format(...).' сум'` with the currency word baked into the model in Cyrillic Russian. The public booking page surfaces this in three places a customer reads: the barber card on step 2, the confirmation summary on step 4, and the success receipt on step 5. A visitor browsing in `uz` sees "Xizmat / Narxi: 50 000 сум" and in `kaa` the same — Cyrillic dropped into a Latin-script page. The rest of the booking flow is fully translated via `lang/{uz,ru,kaa}/booking.php`, so this is the one string that bypasses `__()`.

**Рекомендация.** Add `'currency' => 'soʻm' | 'сум' | 'soʻm'` to each of `lang/uz/common.php`, `lang/ru/common.php`, `lang/kaa/common.php` and change the model accessor to `number_format((int) $this->price, 0, '.', ' ').' '.__('common.currency')`. This fixes the admin screens that use `formattedPrice` at the same time.

**Код:** app/Models/Barber.php:88-90; rendered at resources/views/livewire/pages/booking.blade.php:377, :471, :575

### [high · clarity · M] The public phone lookup reveals a stranger's stored name and birth date to anyone who types their number

`updatedPhone()` fires on a public, unauthenticated page on every 400ms pause while typing. Any normalised 12-digit Uzbek number is looked up and, on a hit, the client's stored `name` and `birth_date` are pushed straight into the visible form fields plus a green "Mijoz topildi — maʼlumotlar bazadan olindi" confirmation. Anyone can type a neighbour's or ex-partner's number and read back their real name and date of birth, and the numeric keyspace is small enough to enumerate. The UI actively advertises the disclosure rather than hiding it, so this is not a theoretical leak.

**Рекомендация.** Stop echoing stored PII to the browser. Keep the lookup, but only set `clientFound` and show a neutral message ("Nomeringiz bazada bor — ismni tasdiqlang") with the name/birth fields left empty for the customer to fill; reconcile server-side inside `confirm()`, where you already merge `$updates` into the client record. If pre-filling is considered essential, mask it (`A••••` / birth year only). Also move the lookup off `.live` onto `.blur` and rate-limit it with the same `RateLimiter` key already used by `confirm()` so the endpoint cannot be enumerated at speed.

**Код:** resources/views/livewire/pages/booking.blade.php:64-88 (autofill), :501 (live lookup), :513-515 ("client found" banner), :528-537 (populated fields)

### [medium · feedback · S] The "no free slots" empty state is unreachable dead code — a fully booked day shows a wall of 24 red buttons

The template guards the slot grid with `@if (count($this->availableSlots) === 0)`, but `availableSlots()` unconditionally returns 24 entries, so that branch can never run and the translated `booking.datetime.no_slots` string ("Bu kuni boʻsh vaqt yoʻq. Boshqa sanani tanlang.") never renders in any of the three languages. When a popular barber is fully booked the customer instead sees 24 identical red tiles with no instruction, and — per the finding above — can still tap them. The check is on the wrong array.

**Рекомендация.** Compare against free slots, not all slots: compute `$free = array_diff(array_column($this->availableSlots,'value'), $this->takenSlots)` once and branch on `count($free) === 0` to show the existing `no_slots` message, ideally with a "try tomorrow" shortcut that `$set`s the date forward. Once working hours are respected (see the hardcoded-hours finding) this branch becomes genuinely reachable.

**Код:** resources/views/livewire/pages/booking.blade.php:428-431 vs :127-136; string defined at lang/uz/booking.php ('datetime.no_slots')

### [medium · clarity · S] The date strip shows no month, hides its scrollbar, and silently caps booking at 14 days

The horizontal picker prints only a weekday abbreviation and a bare day number, so across a month boundary the customer sees "Yak 31" followed by "Dush 1" with nothing indicating September — a real risk of someone believing they booked this month. The container uses `hide-scrollbar` with `overflow-x-auto`, which removes the only visual hint that more dates exist to the right; roughly five tiles fit on a 375px screen, so days 6–14 are invisible to a customer who does not think to swipe. The 14-day horizon is a bare `@for ($i = 0; $i < 14; $i++)` with no message explaining that later dates require phoning the shop.

**Рекомендация.** Render a month label above the strip driven by the selected date (`Carbon::parse($date)->translatedFormat('F Y')`), and add a small month abbreviation inside any tile where `$d->day === 1`. Replace `hide-scrollbar` with a right-edge fade (`after:` gradient) or leave the scrollbar visible so the affordance is obvious. Add a short trailing note using the existing `text-content/35` style pointing customers to the shop phone for dates beyond two weeks.

**Код:** resources/views/livewire/pages/booking.blade.php:400-425; resources/css/app.css:107-113

### [medium · clarity · M] The success screen and the public page as a whole give the customer no reference number, address or phone

`confirm()` stores `$this->confirmedAppointmentId = $appointment->id` but the success step never renders it, so the customer leaves with nothing to quote if they need to change or cancel. The screen says "Yozuvingizni tasdiqlash uchun ... bogʻlanamiz" and promises an SMS 30 minutes before the visit, but the appointment is created as `AppointmentStatus::Pending` and no immediate confirmation SMS is sent — the customer's only proof is a screen they are about to close. Beyond that, the public layout renders nothing but the wizard: `shop_phone`, `shop_address`, `instagram` and `telegram` are all captured on the admin settings screen and surfaced nowhere on the public site, so a customer who wants to call or find the shop cannot.

**Рекомендация.** Show the booking number on step 5 (`#{{ $confirmedAppointmentId }}`) next to the date/time block, and add a `tel:` link plus the address in the same card, read from `Setting::get('shop_phone')` / `Setting::get('shop_address')`. Add a slim public footer to the guest branch of the booking layout with address, `tel:` link and the Instagram/Telegram links from settings, styled with the same `border-content/[0.06]` / `text-content/35` tokens used by the header.

**Код:** resources/views/livewire/pages/booking.blade.php:277 (id stored), :551-587 (never rendered), :566 (sms promise); resources/views/components/layouts/booking.blade.php:62-65 (no footer); resources/views/livewire/pages/admin/settings.blade.php:27-33

### [medium · accessibility · S] Taken slots are communicated by colour and a title tooltip only, and the locked form region carries no ARIA

A busy slot differs from a free one purely by red styling plus `title="{{ __('booking.datetime.taken') }}"` — `title` never appears on touch devices, which is the primary platform here, and colour alone fails for red-green colour blindness. A screen-reader user hears "23:00, button" identically for free and taken slots because there is no `aria-disabled`. The locked name/birth region uses `pointer-events-none opacity-40` on the wrapper; the inputs get `@disabled` but nothing ties the explanatory hint to them, and the step indicator is a row of unlabelled divs with no `aria-label`/`aria-current`, so assistive tech gets no sense of progress. The slot buttons themselves come out at roughly 42px tall (`py-2.5` + `text-sm`), just under the 44px comfortable-thumb target, in a 3-across grid.

**Рекомендация.** On taken slots add `@disabled($isTaken)` plus `aria-label="{{ $slot['label'] }} — {{ __('booking.datetime.taken') }}"` and a small strikethrough or lock glyph so the state is not colour-only. Give the hint paragraph an id and reference it with `aria-describedby` from the name and birth inputs. Wrap the step indicator in `<nav aria-label="{{ __('booking.steps.service') }}">`-style markup and put `aria-current="step"` on the active circle. Bump slots to `py-3` for a 44px+ target.

**Код:** resources/views/livewire/pages/booking.blade.php:434-446 (slots), :519-537 (locked region), :291-314 (step indicator)

### [low · consistency · S] Login uses the removed wire:model.defer modifier and does not focus the phone field

The two login inputs are the only `wire:model.defer` bindings in the entire `resources/views` tree — every other component in the app uses plain `wire:model`. `.defer` was removed in Livewire v3 (deferred is now the default), so the modifier is silently ignored: it works by accident and reads as a leftover from a v2 port, which will mislead the next person editing this file. Separately, barbers and admins hit this screen many times a day on a tablet and the phone field is not focused on load, so every login starts with an extra tap.

**Рекомендация.** Change both bindings to plain `wire:model="phone"` / `wire:model="password"` to match the rest of the app, and add `autofocus` to the phone input so the keyboard opens ready to type. The form already submits on Enter via `wire:submit.prevent`, so no other change is needed.

**Код:** resources/views/livewire/pages/auth/login.blade.php:82, :94

**Сильные стороны области:**
- Eager loading on the barber grid is done right: `Barber::active()->with(['specialization', 'media'])->orderBy('name')->get()` at resources/views/livewire/pages/booking.blade.php:108 avoids an N+1 across photos and specialisation names — the same `with()` habit should be applied to `selectedBarber` (booking.blade.php:117-121) and copied to other list screens.
- The submit-state pattern on step 4 is the best in the app and worth cloning everywhere: rate limiting anchored to a translated field error (booking.blade.php:202-208), `wire:loading.attr="disabled"` plus a swapped label between `booking.confirm.submit` and `booking.confirm.submitting` (:540-545), an inline debounced spinner on the phone lookup (:503-505), and per-field `@error` messages rendered directly under each input (:512, :530, :536).
- Progressive disclosure of the contact form is thoughtfully built: the name and birth fields stay locked with a lock icon and an explanatory hint until a valid phone is entered, and `clearAutofill()` (booking.blade.php:90-97) only wipes values the component itself filled, so a customer's hand-typed name is never destroyed when the phone changes. The `prefers-reduced-motion` block at resources/css/app.css:190-195 that neutralises every animation in the flow is an equally good baseline.

---

## Область: chrome

### [critical · clarity · M] The whole Settings page is write-only — none of its 7 values is read anywhere in the app

settings.blade.php saves shop_name, shop_phone, shop_address, work_start, work_end, instagram and telegram via Setting::set() (lines 48-54), but a repo-wide grep for those keys returns zero other consumers — the only other Setting:: readers are SmsService (sms_enabled_*), NotificationTemplates (template_*, sms_locale) and sms/settings.blade.php. Most damaging: the admin sets opening/closing hours here, yet booking.blade.php:130 generates slots with `for ($h = 0; $h <= 23; $h++)`, so the public booking page offers every hour from 00:00 to 23:00 regardless. Staff fill in the form, see the green 'Sozlamalar saqlandi' banner, and nothing changes anywhere — then clients book 03:00 haircuts. This is the single worst trust problem in the chrome area.

**Рекомендация.** Wire the values up or stop showing them. Minimum viable: in booking.blade.php's availableSlots() computed, read `Setting::get('work_start', '09:00')` / `work_end` and bound the loop to that range (guarding work_end > work_start, and validating it in settings save()); render shop_name/phone/address/instagram/telegram in the guest header/footer of components/layouts/booking.blade.php. Any field that still has no consumer after that should be deleted from the form rather than left as a lie.

**Код:** resources/views/livewire/pages/admin/settings.blade.php:51

### [high · clarity · M] Sidebar is a flat 12-item list with no section headings; it does not scale to 16 admin pages

The $links array (admin-header.blade.php:1-31) renders 12 top-level rows — Kassa, Yozuvlar, Yangi yozuv, Barberlar, Xizmatlar, Mutaxassislik, Mijozlar, Mahsulotlar, Sotuvlar, Qarzlar, SMS, Telegram — plus Sozlamalar and (for super admins) Foydalanuvchilar, and 6 more child rows when both groups are expanded. There is no visual grouping, no separators, no eyebrow labels: daily operational pages (Kassa, Yozuvlar, Yangi yozuv, Sotuvlar, Qarzlar) sit in the same undifferentiated stack as rarely-touched catalog pages (Mutaxassislik, Xizmatlar). At ~42px per row, 18 expanded rows is ~760px, so on a phone drawer the bottom half is below the fold and 'Sozlamalar'/'Foydalanuvchilar' require scrolling past everything. Only SMS and Telegram got the grouping treatment; the rest of the IA did not.

**Рекомендация.** Restructure $links into 3-4 labelled sections using the existing array shape — add entries like `['heading' => __('nav.section_operations')]` rendered as a small uppercase `text-[10px] tracking-[0.2em] text-content/30` row (hidden with `:class="{ 'lg:hidden': collapsed }"` like the other labels): Ish (Kassa, Yozuvlar, Yangi yozuv, Sotuvlar, Qarzlar), Katalog (Barberlar, Xizmatlar, Mutaxassislik, Mahsulotlar, Mijozlar), Marketing (SMS, Telegram — already grouped), Tizim (Sozlamalar, Foydalanuvchilar). This is pure template work in the existing @foreach and adds ~4 keys to lang/{uz,ru,kaa}/nav.php.

**Код:** resources/views/components/admin-header.blade.php:1

### [high · performance-ux · M] Every sidebar click is a full page reload — no wire:navigate anywhere in the chrome

Nav links are plain anchors (`<a href="{{ route($link['route']) }}">` at admin-header.blade.php:124 and :110), so each navigation tears down and re-boots the whole document: re-parsing the Vite bundle, re-initialising Livewire and Alpine, and re-requesting the external stylesheet from fonts.bunny.net (app.blade.php:9) which is render-blocking and third-party. The app already knows the better idiom — clients.blade.php and clients/show.blade.php both use wire:navigate — so front-desk staff get instant transitions on exactly one link in the app and a white flash everywhere else. On a shop's mobile data this is the difference between a snappy tool and a sluggish one.

**Рекомендация.** Add `wire:navigate` to the nav anchors at admin-header.blade.php:110 and :124 (and the logo links at :44/:74), which also gives you Livewire's built-in navigation progress bar for free. Wrap the `<aside>` in `@persist('sidebar')` in the layout so the drawer/collapse Alpine state and scroll position survive the swap. Verify the Kassa <-> Yangi yozuv hop specifically, since booking uses a different layout.

**Код:** resources/views/components/admin-header.blade.php:124

### [high · feedback · S] Settings save has no in-flight state and its confirmation renders off-screen on mobile

The submit button (settings.blade.php:138) has no wire:loading.attr="disabled", no spinner and no wire:target, so a double-tap on a slow connection fires save() twice and the user gets zero acknowledgement that anything is happening. Worse, the success banner is rendered at settings.blade.php:70 — above both form cards — while the button lives at the very bottom of a ~800px form. On a phone the user taps Save, the page does not move, and the green 'saved' banner appears somewhere they cannot see; after 2.5s the x-data timeout hides it again (line 69). Net effect: saving feels like it did nothing. The app already has the correct idiom in login.blade.php:106-113 and orders.blade.php:559-561.

**Рекомендация.** Copy the login button pattern verbatim onto settings.blade.php:138 — `wire:loading.attr="disabled" wire:target="save"`, a `wire:loading.remove` label plus a `wire:loading` spinner span, and `disabled:opacity-60`. Move the confirmation next to the button (or duplicate it there) inside the same flex row as an inline `x-show="saved"` chip, so the feedback appears where the thumb already is.

**Код:** resources/views/livewire/pages/admin/settings.blade.php:138

### [high · accessibility · S] Muted text tokens fall well below AA contrast — nav labels ~3:1, empty state ~1.6:1

Inactive nav labels use `text-content/45` on bg-surface-raised (admin-header.blade.php:129 and :115). In light mode that composites #17191c at 45% over #ffffff ≈ #969696 → ~3.0:1; in dark mode #d7dce2 at 45% over #15171b ≈ #6c6f74 → ~3.5:1. Both fail the 4.5:1 AA threshold for 14px text. It gets worse elsewhere: the users table header is `text-content/30` (users.blade.php:244) ≈ 2:1, and the empty-state message is `text-content/20` (users.blade.php:283) ≈ 1.6:1 in light mode — effectively invisible. A barbershop front desk is a bright, glare-heavy environment on a tablet, and half the staff are reading a second language; this is the kind of thing that makes people squint at every screen all day.

**Рекомендация.** The CSS already defines proper tokens for this — `--content-muted` (#565a61 / #9aa0a8) and `--content-subtle` — that are designed to pass. Replace `text-content/45` with `text-content-muted` on the nav labels, `text-content/30` with `text-content-muted` on table headers, and `text-content/20` with `text-content-subtle` on empty states. It is a mechanical find/replace of the /20, /30, /40, /45 opacity utilities on text across the chrome files.

**Код:** resources/views/components/admin-header.blade.php:129

### [medium · feedback · S] Users page: edit/save/delete are silent, and the inline form opens far above the row you clicked

Every action on this page is a server round-trip with no visual acknowledgement: the edit button (users.blade.php:267), the delete button (:272) and the submit button (:231) have no wire:loading state, and delete() / save() dispatch no success event — save() just sets showForm = false (line 121) and the form vanishes with no toast, unlike settings.blade.php which at least fires 'settings-saved'. Compounding it, the form is rendered at the top of the page (`@if ($showForm)` at :174): clicking Edit on the 15th user scrolls nothing, so the row's data appears to have been swallowed. The user taps Edit again, sees nothing again, and gives up.

**Рекомендация.** Add `wire:loading.attr="disabled" wire:target="save"` plus the spinner span from login.blade.php:106-113 to the submit button, and `wire:loading.class="opacity-50" wire:target="edit"` / `wire:target="delete"` to the row buttons. In edit() and openCreate() add `$this->dispatch('form-opened')` and hook an `x-on:form-opened.window="$el.scrollIntoView({ behavior: 'smooth' })"` on the form wrapper. In save(), dispatch a 'user-saved' event and reuse the exact x-data toast block from settings.blade.php:68-74.

**Код:** resources/views/livewire/pages/admin/users.blade.php:231

### [medium · clarity · S] Users table eager-loads the barber link then never shows it, and delete never mentions the consequence

users() eager-loads `User::with('barber')` (users.blade.php:43) but the table body (:252-280) renders only name, phone and role — the barber relation is loaded on every request and thrown away. So a super admin looking at three users with role 'Barber' cannot tell which barber profile each one drives without opening Edit one at a time, even though the form treats that link as required (:85-94). There is also no marker for the current user's own row (the delete button is simply absent at :271, which reads as an inexplicable gap), and the delete confirmation (:273, lang/uz/users.php 'delete_confirm') asks only 'delete user X?' while delete() silently unlinks their barber profile via the nullOnDelete FK — that barber can no longer log in and nobody is told.

**Рекомендация.** Add a fourth column rendering `{{ $user->barber?->name ?? '—' }}` (the data is already loaded, zero query cost), append a small 'siz' / 'вы' badge to the current user's name row using the existing brass pill classes from :257-261, and extend users.delete_confirm in all three lang files to state that the linked barber profile will lose its login.

**Код:** resources/views/livewire/pages/admin/users.blade.php:43

### [medium · accessibility · S] Mobile drawer has no Escape key, no scroll lock and no active-page semantics

The drawer is driven by a bare `open` boolean on the body x-data (app.blade.php:23). There is no `@keydown.escape.window="open = false"`, so a keyboard/Bluetooth-keyboard user cannot dismiss it; the body keeps scrolling behind the overlay (admin-header.blade.php:62-67) so swiping the drawer often scrolls the page underneath instead; and focus is never moved into the drawer or restored to the hamburger on close. Separately, the `<nav>` at :88 has no aria-label, and the active link is signalled only by brass colour classes (:128) with no `aria-current="page"` — screen-reader users and colour-blind staff get no 'you are here'. The two group toggles at :93 are also missing `aria-expanded`.

**Рекомендация.** On the aside add `@keydown.escape.window="open = false"`; on the body x-data add `$watch('open', v => document.body.classList.toggle('overflow-hidden', v))`. Add `aria-label="{{ __('nav.admin_panel') }}"` to the nav, `:aria-expanded="expanded"` to the group buttons at :93, and `@if(request()->routeIs($link['route'])) aria-current="page" @endif` alongside the existing @class block at :126-130 and :112-116.

**Код:** resources/views/components/admin-header.blade.php:62

### [medium · clarity · S] On phones and tablets nothing shows who is logged in

The signed-in user's name and role are rendered only in the desktop header (app.blade.php:50-53), which is `hidden ... lg:flex` (:30). The mobile top bar (admin-header.blade.php:43-59) shows logo, language, theme and hamburger; the drawer footer (:139-151) shows a bare Logout button with no identity at all. On a shared front-desk tablet where an admin and a barber both have accounts, a barber can be logged in and the operator has no way to notice before recording a sale or a cash-register entry against the wrong account — and since barbers see a one-item sidebar (:37-39), the only clue is the missing menu.

**Рекомендация.** Add the same avatar-initial + name + role block used at app.blade.php:50-53 to the top of the drawer footer at admin-header.blade.php:140, above the logout form, wrapped in @auth. Optionally mirror the initial-circle into the mobile top bar next to the hamburger so identity is visible without opening the drawer.

**Код:** resources/views/components/admin-header.blade.php:140

### [medium · convenience · M] search-select blocks Enter, offers no keyboard navigation, no clear, and no search feedback

This component is the client picker on the two highest-traffic forms (appointments.blade.php:656, orders.blade.php:492). It explicitly swallows Enter (`x-on:keydown.enter.prevent`, search-select.blade.php:11) without selecting anything, so the natural front-desk flow 'type phone digits, hit Enter' does nothing — you must lift your hand and tap. There are no arrow-key bindings, no role="combobox"/listbox semantics, and the dropdown opens on focus showing the first 20 unfiltered clients. There is no wire:loading indicator during the 300ms debounce plus round-trip, so the stale list stays on screen and looks unresponsive. And once a client is picked, the input just holds the label text (selectClient sets $clientSearch = $label, orders.blade.php:106) with no chip and no clear button — if the user then edits that text without picking again, client_id silently still points at the previous client. A related hazard: the label is round-tripped through the wire:click expression via addslashes (search-select.blade.php:20), and ASCII apostrophes are extremely common in Uzbek names (G'ulom, To'lqin), which makes that string fragile for no benefit.

**Рекомендация.** Change the wire:click to pass only the id — `wire:click="{{ $onSelect }}({{ $option->id }})"` — and have selectClient(int $id) look the label up server-side; that removes the escaping hazard entirely. Add `x-on:keydown.enter.prevent="$el.closest('[x-data]').querySelector('button[wire\\:key^=opt-]')?.click()"` to select the first match on Enter, `@keydown.escape="open = false"`, and a `<div wire:loading wire:target="{{ $searchModel }}">` spinner row above the options. Add a small x-on:click clear button that resets both the search string and the id.

**Код:** resources/views/components/search-select.blade.php:11

### [low · clarity · S] All 16 admin pages share one browser tab title

The layout falls back to `{{ $title ?? __('common.admin_title') }}` (app.blade.php:7) and no Volt page in resources/views/livewire/pages/ sets a title — a grep for #[Title] / $title returns nothing. So Kassa, Yozuvlar, Mijozlar, Sotuvlar and Sozlamalar all read 'Admin-panel — Blade Barbershop' in the tab strip and in browser history. Front-desk staff typically keep the appointments board and the cash register open side by side; with identical tabs they pick the wrong one, and history/back-button navigation is unreadable.

**Рекомендация.** Add Livewire's `#[Title(...)]` attribute next to the existing `#[Layout('components.layouts.app')]` on each Volt page, reusing the translation key each page already renders in its <h1> — e.g. `#[Title(__('settings.title').' — Blade')]`. Fifteen one-line additions, no layout change needed since app.blade.php already reads $title.

**Код:** resources/views/components/layouts/app.blade.php:7

### [low · i18n · S] 'Admin Panel' is untranslated in all three locales, and translations are interpolated raw into an Alpine expression

lang/uz/nav.php:4, lang/ru/nav.php:4 and lang/kaa/nav.php:4 all define 'admin_panel' => 'Admin Panel' — the English string is printed under the logo in both the mobile bar (admin-header.blade.php:48) and the sidebar (:78), so a Russian or Karakalpak user sees a lone English phrase in otherwise fully localised chrome. Separately, app.blade.php:35 builds an Alpine expression by string interpolation: `x-text="collapsed ? '{{ __('common.expand') }}' : '{{ __('common.collapse') }}'"`. Today's values are safe only because the Uzbek translations use the modifier letter ʻ (U+02BB) rather than an ASCII apostrophe; the moment anyone types a straight quote into common.expand/collapse in any locale, the expression breaks and the collapse button stops rendering its label. theme-toggle.blade.php:6-7 already does this correctly with @js().

**Рекомендация.** Translate admin_panel per locale (uz 'Boshqaruv paneli', ru 'Админ-панель', kaa 'Basqarıw paneli'). Rewrite app.blade.php:32-36 to hold the strings in x-data via @js — `x-data="{ expandLabel: @js(__('common.expand')), collapseLabel: @js(__('common.collapse')) }"` — and reference `collapsed ? expandLabel : collapseLabel`, matching the theme-toggle idiom.

**Код:** resources/views/components/layouts/app.blade.php:35

**Сильные стороны области:**
- The login submit button is a textbook loading pattern worth cloning everywhere: wire:loading.attr="disabled" + wire:target, a wire:loading.remove label swapped for a spinner + 'submitting' text, and disabled:opacity-60 (resources/views/livewire/pages/auth/login.blade.php:106-113). orders.blade.php:559 and sms/settings.blade.php:139 already reuse it; settings.blade.php and users.blade.php should too.
- Sidebar state handling is genuinely good chrome: the SMS/Telegram group auto-expands when it contains the active route (resources/views/components/admin-header.blade.php:91-92), and the desktop collapse state is persisted to localStorage via an x-init $watch and applied to the content padding without a flash (resources/views/components/layouts/app.blade.php:23-28). The no-flash dark-mode bootstrap script in <head> (app.blade.php:10-18) is the right approach too.
- theme-toggle.blade.php escapes its translated tooltips through @js() (lines 6-7) and broadcasts a 'theme-changed' window event that every other instance listens for (lines 13-14), so the drawer toggle and the header toggle stay in sync — the correct pattern for shared components. service-icon.blade.php is similarly disciplined: one central icon map with a documented fallback to Service::DEFAULT_ICON for unknown keys (line 15) and aria-hidden on the SVG.

---

## Область: cross-consistency

### [critical · feedback · S] Blocked deletes render their error message nowhere — the button looks broken

Both money-bearing delete guards write their error into a field bag whose only renderer lives inside the create/edit form. In `orders.blade.php` `deleteOrder()` calls `$this->addError('cart', __('orders.err_delete_has_payments'))`, but `@error('cart')` is printed at line 402, inside `@if ($showForm)` — and deleting is done from the table with the form closed. `appointments.blade.php` `delete()` does the same with `addError('selectedServices', …)`, rendered at line 795 inside `@if ($showForm && ! $isBarberView)`. The staff member confirms the browser `wire:confirm` dialog, the row does not disappear, and no message appears anywhere. On a front desk this reads as "the app is frozen" and invites repeated clicking. Every other blocked action in the app (e.g. the debt modal) shows its error next to the control.

**Рекомендация.** Add a page-level error strip above the list on both pages, outside the form condition, using the existing danger-strip markup from `orders.blade.php:402-404`: `@error('cart') <p class="mb-4 rounded-xl bg-danger/10 px-4 py-3 text-xs font-bold text-danger">{{ $message }}</p> @enderror` (and `@error('deleteBlocked')` on appointments). Better still, move both guards to a dedicated key such as `deleteBlocked` so the message never competes with form validation.

**Код:** resources/views/livewire/pages/admin/orders.blade.php:267 and resources/views/livewire/pages/admin/orders.blade.php:402; resources/views/livewire/pages/admin/appointments.blade.php:568 and resources/views/livewire/pages/admin/appointments.blade.php:795

### [high · consistency · S] Appointments is the only date-scoped page without a date picker — jumping to a date costs N taps

`orders.blade.php` and `dashboard.blade.php` both scope their data with a plain `<input type="date" wire:model.live="date">` in the page header, styled identically. `appointments.blade.php` — the page barbers and admins live in all day, and the post-login landing page — offers only prev/next day arrows around a formatted label. Booking a client for "in three weeks" or checking last month's day means 20+ taps on `nextDay`, each a full Livewire round trip. The component already exposes a `setDate(string $date)` action, so the capability exists and is simply not wired to a control.

**Рекомендация.** Add the same date input the other two pages use into the date bar between the arrows, bound to the existing property: `<input type="date" wire:model.live="date" class="rounded-xl border border-content/10 bg-content/5 px-4 py-2 text-sm text-content dark:[color-scheme:dark]">`. Add an "Today" reset button beside it, since `date` no longer returns to today once the user wanders.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:622-635 versus resources/views/livewire/pages/admin/orders.blade.php:312-316 and resources/views/livewire/pages/admin/dashboard.blade.php:605-617

### [high · feedback · M] Only 4 of 14 submit buttons disable themselves while saving — the rest invite double submits

`orders.blade.php:559` is the model implementation: `wire:loading.attr="disabled" wire:target="save"` plus an inline spinner and `disabled:opacity-50`. `login.blade.php:106`, `telegram/broadcast.blade.php:136` and `sms/settings.blade.php:139` follow it. Every other save button in the admin has no loading state and no disabled state at all: appointments, clients, barbers, services, products, specializations, users, settings, telegram templates and the client-notes form. On a slow front-desk tablet the button gives zero acknowledgement, so staff tap it again — and `save()` on appointments/clients/products is a create path, so a double tap creates a duplicate record.

**Рекомендация.** Apply the `orders.blade.php:559-563` pattern verbatim to the other submit buttons: add `wire:loading.attr="disabled" wire:target="save"`, the `disabled:cursor-not-allowed disabled:opacity-50` classes, and the same spinner SVG guarded by `wire:loading wire:target="save"`. Because the markup is byte-identical everywhere, extract it once as `<x-submit-button>` and swap the call sites.

**Код:** resources/views/livewire/pages/admin/orders.blade.php:559 versus resources/views/livewire/pages/admin/appointments.blade.php:927, resources/views/livewire/pages/admin/clients.blade.php:176, resources/views/livewire/pages/admin/products.blade.php:159

### [high · feedback · M] Cancelling an appointment needs no confirmation and cannot be undone in the UI

In the appointments row actions the ✗ cancel button sits directly beside the ✓ confirm/complete button — two 32px targets, 6px apart, both icon-only — and `markCancelled` fires with no `wire:confirm`, while the far less consequential delete button right next to it does have one. Worse, the state is a dead end: once `status` is `Cancelled` neither the Pending nor Confirmed branch renders, so no button can move it back, and `edit()`/`save()` deliberately preserve `$existing?->status`. A mis-tap on a phone therefore permanently cancels a booked client (and fires the cancellation Telegram/SMS), with the only recovery being delete-and-recreate. Elsewhere the app guards far cheaper actions: unlinking a Telegram chat asks for confirmation.

**Рекомендация.** Add `wire:confirm="{{ __('appointments.cancel_confirm') }}"` to both `markCancelled` buttons (new key in `lang/{uz,ru,kaa}/appointments.php`), and render a 'reopen' action for `AppointmentStatus::Cancelled` rows that calls the existing `markConfirmed` — the server method already handles it, only the template branch is missing.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:1056 and resources/views/livewire/pages/admin/appointments.blade.php:1077 versus resources/views/livewire/pages/admin/telegram/linked.blade.php:93

### [high · pagination-scale · M] Clients silently truncates at 100 rows while printing the true total right above the table

The clients list caps its query with `->limit(100)` and has no pagination control, yet the subtitle prints `Total: {{ $this->totalClients }}` from an uncapped `Client::count()`. A shop with 1 500 clients shows "Total: 1500" over a 100-row table with nothing indicating the cut — an admin scrolling for an older client concludes the record was deleted. `sms/history.blade.php` already demonstrates the correct house pattern in the same codebase: `->paginate(25)`, `{{ $this->messages->links() }}`, and `resetPage()` on filter change. The other unbounded lists (`telegram/linked`, `debts`, `clients/show` history tabs) share the exposure but at least do not advertise a total they cannot show.

**Рекомендация.** Convert clients to the sms/history pattern: `use Livewire\WithPagination;`, swap `->limit(100)->get()` for `->paginate(25)`, add `public function updatedSearch(): void { $this->resetPage(); }`, and render `{{ $this->clients->links() }}` in the same `mt-6` wrapper used at sms/history.blade.php:290-292. Apply the same treatment to `telegram/linked` and the `clients/show` history tabs afterwards.

**Код:** resources/views/livewire/pages/admin/clients.blade.php:40-42 and resources/views/livewire/pages/admin/clients.blade.php:129 versus resources/views/livewire/pages/admin/sms/history.blade.php:55 and resources/views/livewire/pages/admin/sms/history.blade.php:291

### [medium · feedback · M] CRUD saves give no success confirmation, although the app already has a 'saved' toast idiom

Five screens implement the same pleasant confirmation: the component `dispatch('…-saved')` and an Alpine wrapper flips a green pill for 2.5s — `settings.blade.php`, `telegram/templates.blade.php`, `sms/settings.blade.php`, `clients/show.blade.php` (notes), `telegram/broadcast.blade.php`. None of the eight record-CRUD pages do it: `save()` on products, clients, services, barbers, users, specializations, appointments and orders just sets `showForm = false` and resets. The form vanishes and the user must scan the table to work out whether anything happened — which on an edit of an existing row (same row, subtly changed cell) is genuinely ambiguous.

**Рекомендация.** Reuse the existing idiom rather than inventing one: append `$this->dispatch('saved');` to each `save()` and wrap the page root in the block copied from `settings.blade.php:68-74` (`x-data="{ saved: false }"` + `x-on:saved.window=…` + the green pill). Since the markup is identical five times over, extract it as `<x-saved-toast :message="__('common.saved')" />` and drop it into all thirteen pages.

**Код:** resources/views/livewire/pages/admin/settings.blade.php:56 and resources/views/livewire/pages/admin/settings.blade.php:68-74 versus resources/views/livewire/pages/admin/products.blade.php:75-78 and resources/views/livewire/pages/admin/clients.blade.php:101-104

### [medium · consistency · L] Create/edit uses two different surfaces — an overlay modal on two pages, an inline card on seven

`appointments` and `debts` open a fixed full-screen overlay (`fixed inset-0 z-50` + backdrop + close ✗ + Escape handler). `orders`, `clients`, `barbers`, `services`, `products`, `specializations` and `users` instead expand an inline card above the table (`@if ($showForm)` → `mb-8 … rounded-2xl` card with a header strip and Cancel/Save footer). The two surfaces behave differently in ways staff will notice: the inline card cannot be dismissed with Escape or by clicking away, the modal can; the inline card scrolls the page, the modal traps content in `max-h-[90vh]`; the inline card has no ✗ close affordance. Because both idioms are equally represented, users cannot build one mental model of "how editing works here".

**Рекомендация.** Pick the inline card as the standard (it is the majority, it works better on a phone where a 90vh modal over a virtual keyboard is cramped, and it avoids the backdrop-dismiss data loss noted separately) and convert the appointments form to it, keeping the modal only for the short debts payment dialog. Whichever is chosen, extract the header/footer chrome into a single component so the header strip, Cancel button and Save button are literally shared markup.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:639-643 and resources/views/livewire/pages/admin/debts.blade.php:204-208 versus resources/views/livewire/pages/admin/orders.blade.php:362-366 and resources/views/livewire/pages/admin/specializations.blade.php:99-103

### [medium · accessibility · M] Both modals lack dialog semantics and discard unsaved work on an accidental backdrop tap

The appointments modal and the debts payment modal are plain `<div>`s: no `role="dialog"`, no `aria-modal="true"`, no `aria-labelledby` pointing at their `<h3>`, no focus move on open, no focus trap, and the page behind keeps scrolling. Both also bind the full-bleed backdrop to a destructive action — `wire:click="cancel"` on appointments, `wire:click="cancelPay"` on debts — with no dirty check. The appointments form is long (client search, barber, date, payment split, debt toggle, a repeating services table, times, note); one stray thumb on the dimmed area at the edge of a tablet wipes all of it silently, since `cancel()` calls `resetForm()`.

**Рекомендация.** On both overlay wrappers add `role="dialog" aria-modal="true" aria-labelledby="modal-title"` with the id on the existing `<h3>`, add `x-trap.noscroll` (Alpine focus plugin is already how the app does interactivity) or at minimum `x-init="$nextTick(() => $el.querySelector('input,select')?.focus())"`, and on the long appointments form drop the backdrop `wire:click` (keep the ✗ and Escape) or guard it behind a confirm.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:639-643 and resources/views/livewire/pages/admin/debts.blade.php:204-208

### [medium · accessibility · M] Admin forms and row actions have no accessible names, though login/booking and the header show the app knows how

Two divergences compound. (1) Every admin form label is a bare `<label class="mb-1.5 block …">` with no `for`, and every input has no `id` — so tapping the label does not focus the field (a real loss on a phone) and screen readers announce nothing. The public and auth pages do it correctly with `for`/`id` pairs. (2) The row action buttons — edit, delete, and the products stock −/+ steppers — are icon-only with no `title` and no `aria-label`, while the appointments status buttons on the very same kind of row do carry `title="…"` and the header's menu buttons carry `aria-label`. A new admin cannot tell the pencil from the trash without hovering, and hover does not exist on the tablets this app runs on.

**Рекомендация.** Add `id` to each admin input and the matching `for` to its label (mechanical, mirrors `login.blade.php:77-83`). Add `title="{{ __('common.edit') }}"` / `title="{{ __('common.delete') }}"` plus the same string as `aria-label` to every icon-only row button, exactly as `appointments.blade.php:1052` already does with `title="{{ __('common.confirm') }}"`; add `aria-label` to the products stock steppers.

**Код:** resources/views/livewire/pages/admin/specializations.blade.php:107-109 and resources/views/livewire/pages/admin/products.blade.php:222-229 versus resources/views/livewire/pages/auth/login.blade.php:77-83 and resources/views/livewire/pages/admin/appointments.blade.php:1052

### [medium · performance-ux · S] Money fields use wire:model.live with no debounce, against the app's own debounce convention

The app has a clear debounce convention for live-bound text: `search-select.blade.php` uses `.debounce.300ms`, the clients search uses `.debounce.300ms`, the booking phone field uses `.debounce.400ms`. The money inputs break it: cash amount, card amount, debt amount and each service amount are bound with bare `wire:model.live`. Typing `150000` fires six server round trips, each re-rendering the whole appointments modal (which re-runs `filteredClients`, `barbers`, `services`, `timeSlots`). On a front-desk tablet the digits visibly lag and the caret can jump — precisely on the fields where a wrong number puts the cash register out of balance.

**Рекомендация.** Change these to `wire:model.live.debounce.500ms` (numbers need a longer pause than search). The only reason they are live at all is to keep the running total in sync, and a 500ms debounce preserves that. `selectedServices.*.service_id` should stay bare `.live` since a select fires once.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:714 and resources/views/livewire/pages/admin/orders.blade.php:459 versus resources/views/components/search-select.blade.php:11 and resources/views/livewire/pages/admin/clients.blade.php:134

### [medium · responsive · S] The clients toolbar cannot wrap and overflows narrow phones, unlike every other page header

The clients header puts a fixed `w-64` (256px) search input and the Add button inside `<div class="flex items-center gap-3">` — no `flex-wrap`. At 360px the two children need roughly 256 + 12 + ~110 = 378px and cannot break, so the row is squeezed or pushes the page into horizontal scroll. `orders.blade.php` solves the identical layout with `flex flex-wrap items-center gap-3`, and the outer header wrappers on every page already use `flex-wrap`; only the inner action group on clients omits it. This is the page front-desk staff open most often on a phone to look someone up.

**Рекомендация.** Change the wrapper to `flex flex-wrap items-center gap-3` and make the input fluid: `w-full sm:w-64` (the search box then takes the full width on its own line and the Add button drops beneath), matching `orders.blade.php:311`.

**Код:** resources/views/livewire/pages/admin/clients.blade.php:131-135 versus resources/views/livewire/pages/admin/orders.blade.php:311

### [low · consistency · M] Money is formatted three different ways, including two copies of the same private helper

The same so'm amount is produced by three mechanisms: model accessors (`$product->formattedPrice`, `$order->formattedTotal`, `$appointment->formattedDebt`), raw inline `number_format($x, 0, '.', ' ') . ' ' . __('common.currency')` sprinkled through the templates (97 occurrences across 8 files), and two identical per-page helpers — `money()` in the client profile and `formatSum()` in the dashboard, both with the exact same body. Any change to the separator or to where the currency word sits (relevant for uz/kaa) has to be made in three places, and today already produces inconsistent output: some tiles print the amount without the `common.currency` suffix while their neighbours include it.

**Рекомендация.** Keep the model accessors as the single source and add one shared Blade helper for loose sums — e.g. `<x-money :amount="$total" />` wrapping `number_format($amount, 0, '.', ' ').' '.__('common.currency')` with the `tabular-nums` class the app already uses — then delete the duplicated `money()`/`formatSum()` helpers and replace the inline `number_format` call sites.

**Код:** resources/views/livewire/pages/admin/clients/show.blade.php:156-159 and resources/views/livewire/pages/admin/dashboard.blade.php:561 versus resources/views/livewire/pages/admin/debts.blade.php:181

**Сильные стороны области:**
- The list-page shell is genuinely uniform and worth protecting: `rounded-2xl` card → `overflow-x-auto` → `<table class="w-full text-left text-sm">` → `@forelse` with a `colspan` empty-state row, plus `hidden … sm:table-cell` to drop secondary columns on phones and a compact stacked repeat of that data under the primary cell. Eight list pages share it byte-for-byte (resources/views/livewire/pages/admin/services.blade.php:201-262, resources/views/livewire/pages/admin/barbers.blade.php:296-365) — the mobile column-hiding trick in particular should be copied into the dashboard's 7-column salary tables, which currently only scroll.
- The SMS history page is the reference implementation for data that grows: `->paginate(25)` with `{{ $this->messages->links() }}`, two `wire:model.live` filter selects, and `updatedStatus()`/`updatedContext()` calling `resetPage()` so filtering never lands the user on an empty page 4 (resources/views/livewire/pages/admin/sms/history.blade.php:43-56, 150-158, 291). Every other list in the admin should be converted to exactly this shape.
- The lightweight save-confirmation idiom — component `dispatch('…-saved')` plus an Alpine wrapper that shows a green pill for 2.5s and self-clears via `clearTimeout($el._t)` — is cheap, non-blocking and needs no dependency (resources/views/livewire/pages/admin/settings.blade.php:68-74, resources/views/livewire/pages/admin/clients/show.blade.php:236-245). It deserves to be extracted into one component and used on every mutating screen.

---

## Область: gap: The barber role's entire experience is unreviewed. `Restrict

### [critical · convenience · M] A barber cannot change any appointment status — every confirm/complete/cancel needs an admin

For a barber the appointments page is the entire product (RestrictBarberAccess redirects them off every other admin route, admin-header filters the sidebar to one link), yet the whole actions column is stripped: `@unless ($isBarberView)` hides the header cell (appointments.blade.php:957) and the body cell (appointments.blade.php:1047), and `markConfirmed`/`markCompleted`/`markCancelled` all start with `abortIfBarber()` (lines 541, 548, 555). So the person who actually performs the service cannot mark the client confirmed, finished or no-show; they must walk to the front desk and ask an admin, or the day's statuses simply stay wrong — which then corrupts the salary numbers, because only Completed visits accrue a share (BarberMenuHandler.php:47-52). The guard is also stricter than it needs to be: the component already knows the barber's own id (`ownBarberId`, appointments.blade.php:94-95, 104-108) and already scopes the query by session (lines 173-183), so an ownership check is available.

**Рекомендация.** Keep `abortIfBarber()` on `openCreate`/`edit`/`save`/`delete` (money and client data stay admin-only), but replace it in `markConfirmed`/`markCompleted`/`markCancelled` with an ownership guard in the same session-first idiom already used at appointments.blade.php:115-118, e.g. `$own = $this->sessionBarberId(); $a = Appointment::findOrFail($id); abort_if($own !== null && $a->barber_id !== $own, 403);`. Then turn the `@unless ($isBarberView)` at line 1047 into an `@if/@else` that renders a barber-scoped cell containing only the existing complete and cancel buttons (reuse the markup at lines 1062-1071), adding `wire:confirm="{{ __('appointments.cancel_confirm') }}"` on cancel and `wire:loading.attr="disabled" wire:target="markCompleted"` so a double tap on a phone cannot fire twice. Extend tests/Feature/BarberRoleTest.php: a barber may complete their own appointment and still gets 403 on another barber's.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:957, resources/views/livewire/pages/admin/appointments.blade.php:1047, resources/views/livewire/pages/admin/appointments.blade.php:541

### [high · consistency · L] Barbers get an earnings view in the Telegram bot but nothing in the CRM

`BarberMenuHandler::earnings()` computes today / this week / this month pay with the barber's percent and renders it as a three-line message (BarberMenuHandler.php:33-77), reached by a persistent keyboard button (Keyboards.php:14). The same person logging into the CRM sees no money at all: the sidebar is filtered to the single appointments link (admin-header.blade.php:37-39) and the dashboard that holds the salary table is blocked by RestrictBarberAccess.php:21-23. A barber therefore sees strictly more of their own data in a chat window than in the product built for them, and the CRM's one barber page is read-only. The formula already lives on the models (`Appointment::salaryShare()`, `DebtPayment::salaryShare`), so the page is presentation work, not new business logic.

**Рекомендация.** Add `Volt::route('/my-earnings', 'pages.admin.my-earnings')->name('earnings')` inside the admin group and allow it in RestrictBarberAccess (`! $request->routeIs('admin.appointments', 'admin.earnings')`). Build the Volt page from the barber's own id via `auth()->user()->barber` (never a public property), reusing the exact period closure from BarberMenuHandler.php:46-62 — best extracted into a small `App\Support\BarberEarnings` class so bot and page cannot drift — and render three stat tiles plus a list of the month's completed visits with `->paginate()`, matching the house pattern in admin/sms/history.blade.php. Add the nav entry for barbers by appending it to `$links` in the barber branch at admin-header.blade.php:37-39, with `nav.my_earnings` in lang/{uz,ru,kaa}/nav.php.

**Код:** app/Telegram/Handlers/BarberMenuHandler.php:33, resources/views/components/admin-header.blade.php:37, app/Http/Middleware/RestrictBarberAccess.php:21

### [high · clarity · S] A barber-role user with no linked barber profile silently sees the whole shop's day

`sessionBarberId()` returns `$user->barber?->id` (appointments.blade.php:136-141). If that is null the query's `when($ownBarberId !== null, ...)` never fires and `barberFilter` is also null, so the `when($ownBarberId === null && $this->barberFilter, ...)` branch is skipped too — the barber sees every barber's appointments for the day (appointments.blade.php:173-183), including other masters' clients, phone numbers and prices, with no filter select to narrow it (hidden at line 598) and no actions. This is reachable in production: the barber profile can be deleted independently of the user account (admin/barbers.blade.php:136-138 does a plain `Barber::findOrFail($id)->delete()`), and the barber↔user link is only enforced when the user is created or edited (admin/users.blade.php:84-95). Nothing on screen tells the barber (or the admin who broke the link) that anything is wrong.

**Рекомендация.** Make the missing profile an explicit state instead of a silent fallback. In `appointments()`, after `$ownBarberId = $this->sessionBarberId();` add `if (auth()->user()?->isBarber() && $ownBarberId === null) { return collect(); }`, and in the template render a dedicated empty state in place of the table — reuse the existing empty-row styling at appointments.blade.php:1086-1090 with a new `appointments.no_barber_profile` key in lang/{uz,ru,kaa} telling the master to ask an admin to link their profile. Cover it in tests/Feature/BarberRoleTest.php with a barber user whose Barber row was deleted.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:173, resources/views/livewire/pages/admin/appointments.blade.php:136, resources/views/livewire/pages/admin/barbers.blade.php:136

### [high · feedback · S] The barber redirect is completely silent — the app looks broken rather than restricted

RestrictBarberAccess.php:21-23 does a bare `redirect()->route('admin.appointments')` with no message. A barber who types the domain's `/admin`, restores a bookmark, or picks up a shared front-desk tablet whose last page was `/admin/debts` is bounced to the appointments list with zero explanation — indistinguishable from a bug, and the natural reaction is to ask an admin whether their account is broken. The app has no flash-message region at all (nothing in components/layouts/app.blade.php:77-79 renders `session('status')`; grep finds no flash usage anywhere), so nothing catches these cases today.

**Рекомендация.** Redirect with a marker — `return redirect()->route('admin.appointments')->with('barberRestricted', true);` — and render a one-off banner at the top of the appointments view (just before the header block at appointments.blade.php:592-597) using the app's existing panel styling: `@if (session('barberRestricted')) <div class="mb-6 rounded-2xl border border-brass/20 bg-brass/5 px-4 py-3 text-sm text-brass-ink">{{ __('appointments.barber_restricted') }}</div> @endif`, with the string added to lang/{uz,ru,kaa}/appointments.php. This is also the minimal seed of a flash region the rest of the admin can reuse.

**Код:** app/Http/Middleware/RestrictBarberAccess.php:21, resources/views/components/layouts/app.blade.php:77

### [medium · clarity · S] The barber's row wastes its space on their own name and hides the money on phones

In the barber view every row's barber column is the barber themselves: the header at appointments.blade.php:949 and the avatar+name cell at lines 982-989 are pure noise, and the mobile-only summary line at lines 978-980 leads with `{{ $appointment->barber?->name }} · services` — i.e. on the phone where masters actually work, the most prominent extra fact is their own name. Meanwhile the column that carries the price, the cash/card split and the debt badge is `hidden ... md:table-cell` (lines 950 and 990-1032), so a barber on a phone sees no amount at all and no debt warning — even though the amount is the basis of their own pay (BarberMenuHandler.php:47-52).

**Рекомендация.** Wrap the barber `<th>` (line 949) and `<td>` (lines 982-989) in `@unless ($isBarberView)` exactly like the actions column at lines 957 and 1047, and drop `$isBarberView ? 5 : 6` to `4` in the empty-row colspan at line 1088. In the mobile summary line, show what matters instead: services plus `{{ $appointment->formattedPrice }}` and, when `$appointment->hasDebt`, the same danger-tinted debt chip already built at lines 1025-1030.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:949, resources/views/livewire/pages/admin/appointments.blade.php:978, resources/views/livewire/pages/admin/appointments.blade.php:990

### [medium · consistency · S] The barber's only navigation affordance leads out of the CRM to the public booking form

The `nav.booking` link is filtered out of a barber's sidebar (admin-header.blade.php:37-39) and `openCreate` aborts for barbers (appointments.blade.php:266-271), yet both brand logos — the mobile top bar at admin-header.blade.php:44 and the sidebar at line 74 — link to `route('booking')`, which sits outside the admin route group and is therefore untouched by RestrictBarberAccess. Tapping the logo (the instinctive "go home" gesture, and for a barber the only other clickable thing in the chrome) drops them onto the public booking wizard, rendered with the admin sidebar because layouts/booking.blade.php:25-30 includes `x-admin-header` for authenticated users. That page happily creates a Pending appointment for anyone (booking.blade.php:263-269), so the role restriction the rest of the app enforces is one logo tap away from being irrelevant.

**Рекомендация.** Point the logo at the user's own home: `href="{{ auth()->user()?->isBarber() ? route('admin.appointments') : route('booking') }}"` in both places (admin-header.blade.php:44 and :74). If barbers should genuinely be kept off the public form, also widen the middleware's allow-list check to cover the booking route, or apply RestrictBarberAccess to it.

**Код:** resources/views/components/admin-header.blade.php:44, resources/views/components/admin-header.blade.php:74, resources/views/livewire/pages/booking.blade.php:263

### [medium · convenience · S] No "today" shortcut or date picker — the barber's one page can only be navigated one arrow tap at a time

The date bar offers only `prevDay`/`nextDay` arrows (appointments.blade.php:622-635); `$date` is never bound to an input and there is no reset. For an admin that is mildly annoying; for a barber it is the entire navigation surface of the entire product. After paging back a week to check a past visit they must tap the arrow seven more times to return, and a Livewire round-trip fires on every tap. The Telegram bot they already use gives them Today and Tomorrow as one-tap buttons (Keyboards.php:10-12), so the CRM is measurably clumsier than the chat.

**Рекомендация.** Add to the date panel, between the arrows: `<input type="date" wire:model.live="date" class="...">` bound to the existing `$date` (the parse is already defensive at appointments.blade.php:124-131) and a `wire:click="setDate('{{ now()->toDateString() }}')"` Today button using the existing `setDate()` method (line 238) so `form_date` stays in sync. Disable the Today button via `@disabled($date === now()->toDateString())` so its state is readable at a glance.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:622, resources/views/livewire/pages/admin/appointments.blade.php:238, app/Telegram/Keyboards.php:10

### [medium · clarity · S] On a phone the user never sees who is logged in — only the desktop header shows name and role

The identity chip with avatar, name and `role->label()` lives exclusively in the desktop header (`hidden ... lg:flex`, components/layouts/app.blade.php:30 and 46-56). The mobile top bar carries only language, theme and a hamburger (admin-header.blade.php:51-58), and the drawer holds just a logout button and the theme toggle (lines 139-157). Barbers are the phone-first role, and front-desk phones/tablets are shared: a master can spend a whole shift on the appointments list without any signal that the session belongs to a colleague — and after the actions from finding 1 exist, they would be marking visits completed under someone else's account.

**Рекомендация.** Reuse the desktop chip markup from components/layouts/app.blade.php:50-53 at the top of the mobile drawer (right under the logo block at admin-header.blade.php:73-85, inside a `lg:hidden` wrapper) so the drawer opens with "initial · name · role" above the single nav link and the logout button.

**Код:** resources/views/components/layouts/app.blade.php:50, resources/views/components/admin-header.blade.php:51, resources/views/components/admin-header.blade.php:139

### [low · convenience · S] Full sidebar chrome — collapse toggle, drawer, scrollable nav — wraps a one-item menu

After the barber filter at admin-header.blade.php:37-39 the nav renders exactly one link. The desktop still shows the collapse/expand button with its "Collapse"/"Expand" label (components/layouts/app.blade.php:32-36) which toggles a 64px sidebar down to 20px for a single icon, the nav is still `overflow-y-auto` (admin-header.blade.php:88), and on mobile the barber must tap the hamburger (lines 54-57) and open a full-screen drawer with backdrop to reach the page they are already on. Every one of these controls does nothing useful for this role.

**Рекомендация.** Gate the chrome on menu size rather than hardcoding the role in two places: in admin-header set `@php($singleLink = count($links) === 1)` and skip the hamburger button when true; in components/layouts/app.blade.php:32-36 wrap the collapse toggle in `@unless(auth()->user()?->isBarber())` (and keep the `lg:pl-64` padding logic unchanged). For barbers, moving the logout into the mobile top bar removes the need for the drawer altogether.

**Код:** resources/views/components/admin-header.blade.php:88, resources/views/components/layouts/app.blade.php:32, resources/views/components/admin-header.blade.php:54

### [low · feedback · S] The barber's read-only day never refreshes, so new bookings appear only after a manual reload

Appointments created from the public booking page land as Pending (booking.blade.php:263-269) and admins add walk-ins from the same list, but the barber's page has no polling and — with the actions column stripped (appointments.blade.php:1047) — no interaction that would trigger a Livewire round-trip either. A master who opens the page at the start of the shift keeps looking at the schedule as it was hours ago, which is precisely why the Telegram "Сегодня" button (Keyboards.php:10) gets used instead.

**Рекомендация.** Add `wire:poll.60s` to the table wrapper at appointments.blade.php:937 for the barber view only (`@class`/attribute conditional on `$isBarberView`), so the read-only schedule stays current without touching the admin's heavier page. The `appointments` computed property is already a single eager-loaded day query (lines 176-185), so the cost per poll is one indexed range scan.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:937, resources/views/livewire/pages/booking.blade.php:263

### [medium · i18n · M] The barber's Telegram surface is hardcoded Russian while their CRM is Uzbek Latin

Every barber-facing bot string is a Russian literal with no `__()`: the schedule titles 'Расписание на сегодня' / 'Расписание на завтра' (BarberMenuHandler.php:25, 30), the empty-day line (line 96), the earnings block with 'Ваш заработок' (line 69), the currency suffix 'сум' (line 124), the link prompt 'Сначала привяжите профиль командой /start.' (line 116), and the keyboard captions (Keyboards.php:10-14). The CRM the same barber logs into defaults to Uzbek Latin with three locales driven by lang/{uz,ru,kaa}, so one role gets two languages across two surfaces — and a kaa- or uz-only master cannot read their own pay message at all.

**Рекомендация.** Move these strings into lang/{uz,ru,kaa}/telegram.php (the file already exists) and call `__('telegram.barber_today')` etc., wrapping each handler in `App::setLocale(...)` resolved from the linked user (via TelegramLinker) or falling back to `config('app.locale')`. Reuse `__('common.currency')` for the suffix instead of the literal 'сум' so the bot and the appointments table (appointments.blade.php:1013-1014) agree.

**Код:** app/Telegram/Handlers/BarberMenuHandler.php:69, app/Telegram/Handlers/BarberMenuHandler.php:116, app/Telegram/Keyboards.php:10

**Сильные стороны области:**
- Role state is treated as a template hint, never as authority: `isBarberView`/`ownBarberId` are `#[Locked]` (appointments.blade.php:91-95) while `sessionBarberId()`/`abortIfBarber()` re-read the role from the session for both the query and every mutator (lines 115-118, 173-183). debts.blade.php:37 repeats the same guard. This is the pattern any new role-aware page should copy.
- Data access is narrowed, not just the UI: `filteredClients()` returns an empty collection for barbers (appointments.blade.php:148-150), so hiding the modal is backed by the reference data actually being unreachable.
- Role-aware layout keeps its details right — the empty row's colspan follows the column count (`colspan="{{ $isBarberView ? 5 : 6 }}"`, appointments.blade.php:1088), so the barber's table does not collapse into a misaligned empty state.

---

## Область: gap: There is no failure surface anywhere in the app — the review

### [critical · feedback · M] No transport-failure surface anywhere: a dropped save on the visit/sale form is silent and the entry is lost

`resources/js/app.js` is a single `//` comment — no `Livewire.hook('request', …)`, no `fail` handler, and `wire:offline` / `wire:dirty` appear nowhere in `resources/` (grep returns zero hits; only `wire:loading` exists, on 8 buttons). `bootstrap/app.php:24` has an empty `withExceptions` closure and there is no `resources/views/errors/` directory. So on a phone that drops to one bar mid-save: the appointment modal (`appointments.blade.php:651` `<form wire:submit="save">`) posts, nothing comes back, and the submit button at `appointments.blade.php:927` has no `wire:loading` state at all — it neither spins nor disables, so the staff member cannot tell whether the visit was recorded. A 500 instead produces Livewire's stock raw-HTML overlay in English; a hard network drop produces nothing visible at all. Either way the operator's only move is to close the modal or reload, which discards a fully typed visit (client, barber, services with amounts, cash/card split, debt, times). The server side is careful here — `appointments.blade.php:490` and `orders.blade.php:226` wrap writes in `DB::transaction` with `lockForUpdate` — so the data is safe; it is only the operator who is left guessing.

**Рекомендация.** Add a small cross-cutting layer in `resources/js/app.js` (it is loaded at `app.blade.php:19`, before `@livewireScripts` at :82, so a `document.addEventListener('livewire:init', …)` registration works as-is): `Livewire.hook('request', ({ fail }) => fail(({ status, preventDefault }) => { preventDefault(); showBanner(status) }))` where `showBanner` toggles a fixed bottom banner already rendered (hidden) in `app.blade.php` with a translated message and a Retry button that re-fires the last commit — the component snapshot is still in the DOM, so the typed form survives. Additionally put `wire:offline.attr="disabled"` and a `wire:offline` label swap on the submit buttons in `appointments.blade.php:927` and `orders.blade.php:559` (a built-in Livewire directive, zero JS) so a save cannot even be attempted with no connection.

**Код:** resources/js/app.js:1

### [critical · feedback · M] Session expiry mid-shift 419s into an untranslated browser dialog that reloads and throws the half-entered sale away

`config/session.php:35` sets a 120-minute idle lifetime and `:37` leaves `expire_on_close` false, with the database driver. A tablet parked at the front desk over a lunch break exceeds that, and the next tap returns 419. Because `resources/js/app.js:1` registers nothing, Livewire's default page-expired behaviour applies: a browser-native `confirm('This page has expired…')` — English regardless of the uz/ru/kaa locale the whole rest of the UI honours — followed by `location.reload()`. The reload discards whatever was in the sale cart (`orders.blade.php:46` `$cartItems`) or the appointment modal, because that state lives only in the Livewire snapshot. `bootstrap/app.php:23-25` is empty and there are no custom error views, so a full-page 419 (e.g. the `<form method="POST">` logout at `app.blade.php:62`) is Laravel's stock English 'Page Expired' page too. Nothing anywhere warns that the session is about to expire.

**Рекомендация.** Two parts, both small. (1) In `resources/js/app.js`, intercept the 419 in the same `Livewire.hook('request', ({ fail }) => …)` layer: `preventDefault()` the default reload and show a translated modal offering 'Sign in again' (opens `route('login')` in a new tab) plus 'Retry' which re-sends the commit — after re-auth the snapshot is still valid, so the typed sale is preserved instead of destroyed. (2) Raise `SESSION_LIFETIME` in `.env` to cover a full shift (e.g. 720) so a lunch break no longer logs the front desk out; the session cookie stays `http_only`/`same_site=lax` so the exposure is limited to the shop's own device.

**Код:** config/session.php:35

### [high · feedback · S] Blocked deletes fail completely silently: the error is rendered inside a form that is closed when the delete fires

`orders.blade.php:267` blocks deleting a sale that has debt repayments with `addError('cart', __('orders.err_delete_has_payments'))`. The only place that key is rendered is `orders.blade.php:402`, which sits inside `@if ($showForm)` (opened at :362). Deletes are triggered from the list row at `orders.blade.php:634`, where `$showForm` is false — so the operator confirms the native `wire:confirm`, the request succeeds with a 200, the row does not disappear, and no message appears anywhere. The identical bug exists in appointments: `appointments.blade.php:568` adds the error to `selectedServices`, whose only renderer is `appointments.blade.php:795`, inside the modal `@if ($showForm && ! $isBarberView)` at :638. The translations are fully written in all three locales (lang/{uz,ru,kaa}/orders.php:32) and are simply never shown. A staff member with no feedback will tap delete repeatedly and then conclude the app is broken.

**Рекомендация.** Render these guard errors outside the form container. Add a dismissible banner just above the list table in both files — `@error('cart') <div class="mb-4 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm text-danger">{{ $message }}</div> @enderror` above `orders.blade.php:571` and the same for `selectedServices` above `appointments.blade.php:937` — reusing the existing danger-token styling. Keep the in-form copy for the modal case.

**Код:** resources/views/livewire/pages/admin/orders.blade.php:402

### [high · feedback · S] Dashboard's green 'refreshes every 60s' badge is static decoration — cash figures go stale silently when polling fails

`dashboard.blade.php:565` puts `wire:poll.60s` on the root div, and `:619-625` renders an always-on green pill with an `animate-ping` dot reading 'Har 60 soniyada yangilanadi' / 'Обновление каждые 60 сек' (lang/uz/dashboard.php:9). That badge is unconditional markup: it keeps pulsing green when the tablet is offline, when the session has expired, or when polls are simply failing, because there is no `wire:offline` anywhere in the app and `resources/js/app.js:1` registers no failure hook. Meanwhile the numbers directly above it — `receivedTotal` at `:644`, `cashTotal` at `:662`, `cardTotal` at `:674` — are what staff read to count the drawer and close the day. A frozen figure that is confidently labelled 'live' is worse than no auto-refresh at all, because the operator has no reason to pull-to-refresh.

**Рекомендация.** Make the badge report reality instead of intent. Print the render time inside it — `{{ __('dashboard.auto_refresh') }} · {{ now('Asia/Tashkent')->format('H:i') }}` — so a stalled poll shows a visibly stale clock at zero cost (the value is regenerated on every successful poll, and freezes exactly when polling stops). Then add the offline variant with the built-in directive: `wire:offline.class="border-danger/30 bg-danger/10 text-danger"` on the pill plus a `<span wire:offline>{{ __('dashboard.offline') }}</span>` / `<span wire:offline.remove>…</span>` swap, with the new key added to lang/{uz,ru,kaa,en}/dashboard.php.

**Код:** resources/views/livewire/pages/admin/dashboard.blade.php:619

### [high · feedback · S] A stray thumb on the modal backdrop wipes a fully typed appointment with no confirmation

The appointment modal closes on backdrop click (`appointments.blade.php:642` `wire:click="cancel"`) and on Escape (`:641` `x-on:keydown.escape.window="$wire.cancel()"`), and `cancel()` at `:577` calls `resetForm()` which `reset()`s client, barber, services, price, note, times, cash/card and debt unconditionally. There is no dirty check anywhere in the app — `wire:dirty` has zero occurrences in `resources/` — and no confirmation. On a phone the panel is `max-w-2xl` inside a full-screen backdrop with `p-4`, so the tappable dead zone around it is large and sits exactly where a thumb rests. Losing a visit with five service rows, a cash/card split and a debt amount to one mis-tap is the same lost-work failure as a dropped request, but self-inflicted and far more frequent. Note the destructive row actions in the same file already guard themselves properly (`:1078` `wire:confirm`), so the modal is the outlier.

**Рекомендация.** Drop `wire:click="cancel"` from the backdrop at `appointments.blade.php:642` (keep the X at :646 and the Cancel button at :923 as the deliberate exits) and put the app's existing confirm idiom on those two: `wire:confirm="{{ __('appointments.discard_confirm') }}"`, matching the `wire:confirm` already used at :1078. Guard the Escape handler the same way. Add the new key to lang/{uz,ru,kaa,en}/appointments.php.

**Код:** resources/views/livewire/pages/admin/appointments.blade.php:642

### [high · feedback · S] Clearing the month picker crashes the dashboard into a stock error overlay — the day path is guarded, the month path is not

`dashboard.blade.php:36-43` defensively wraps date parsing in try/catch, with a comment stating exactly why ('$date is a client field… otherwise a bad value gives a 500 instead of an empty list'). The month path never got the same treatment: `monthlyAppointments` (`:373`), `monthlyOrders` (`:387`), `monthlyDebtPayments` (`:402`) and `dailyChartData` (`:523`) all call `Carbon::parse($this->month.'-01', 'Asia/Tashkent')` bare, while `$month` is bound with `wire:model.live` at `:614` with no `#[Validate]` and no `updatedMonth` guard. Clearing an `<input type="month">` (a one-tap gesture on mobile) posts an empty string, so the parse receives `'-01'`. Because `bootstrap/app.php:24` is empty and there are no custom error views, the resulting 500 surfaces as Livewire's raw untranslated error overlay and the month tab is dead until a manual reload — and `wire:poll.60s` at `:565` then re-throws it every minute. `monthString()` at `:54-58` already has the try/catch, which shows the guard was intended and only partially applied.

**Рекомендация.** Extract a private `monthStart(): Carbon` mirroring `dayStart()` at `:36` — try `Carbon::parse($this->month.'-01', 'Asia/Tashkent')->startOfMonth()`, catch and fall back to `Carbon::now('Asia/Tashkent')->startOfMonth()` — and call it from lines 373, 387, 402 and 523 (and from `monthString()`), so a cleared field falls back to the current month instead of taking the page down.

**Код:** resources/views/livewire/pages/admin/dashboard.blade.php:373

### [medium · i18n · M] Every failure state the staff can reach is English-only, in an app that is otherwise fully translated into three locales

The app translates meticulously — four `lang/` directories, `__()` on essentially every string, including error copy like `err_delete_has_payments` in all three locales. But no failure vocabulary exists: `lang/uz/common.php` has `loading` and nothing for offline / connection lost / retry / session expired, there are no `lang/*.json` files (so the framework's own `__('Page Expired')` / `__('Server Error')` strings have no translations), and `resources/views/errors/` does not exist, so 419, 500 and the deploy-time 503 all render Laravel's stock English pages. Livewire's default 419 dialog and error overlay are English too. The net effect is that the only screens the front desk sees when something goes wrong are the only screens they cannot read.

**Рекомендация.** Add `offline`, `connection_lost`, `retry`, `session_expired`, `try_again` to lang/{uz,ru,kaa,en}/common.php, and add `resources/views/errors/419.blade.php`, `500.blade.php` and `503.blade.php` built on the existing `components.layouts.auth` layout (`resources/views/components/layouts/auth.blade.php`) so they inherit the theme, fonts and locale — each with a single translated line and a button back to `route('admin.dashboard')`. Use the same keys from the JS failure banner in finding 1 so the vocabulary is shared.

**Код:** resources/views/components/layouts/app.blade.php:82

### [medium · performance-ux · S] The dashboard polls its entire heavy payload every 60s regardless of tab or visibility, starving real taps on bad mobile data

`wire:poll.60s` sits on the root element at `dashboard.blade.php:565`, so every minute the whole component re-runs and re-renders ~1300 lines of markup. Livewire polls the component, not the element, so this fires whether the user is on the day tab or the month tab — and the month tab's computeds are the expensive ones: `monthlyAppointments` (`:376`), `monthlyOrders` (`:390`), `monthlyDebtPayments` (`:405`) and `dailyChartData` (`:526-533`) each pull a full month of rows with `->get()`, and `dailyChartData` then filters that whole collection once per day of the month in PHP. A month of data changes essentially never inside 60 seconds. On a phone on weak data this poll occupies the connection that the user's own taps (date change at `:608`, tab switch at `:582`) need, so the interaction they are waiting on queues behind a refresh nobody asked for — and with no in-flight indicator anywhere on this page, that reads as the app being frozen.

**Рекомендация.** Scope the poll to when it is actually useful: make it conditional on the tab — `@if ($activeTab === 'day') wire:poll.60s.visible @endif` on the root div at `:565` — so the month tab does not auto-refresh, and `.visible` stops polling when the tablet's screen is off or the page is scrolled away. Pair it with the freshness timestamp from finding 4 so users can still see how current the day figures are.

**Код:** resources/views/livewire/pages/admin/dashboard.blade.php:565

**Сильные стороны области:**
- Server-side failure posture is already right: money mutations run inside `DB::transaction` with `lockForUpdate` (appointments.blade.php:490-524, orders.blade.php:226-253, and orders.blade.php:275-286 which deletes and restocks atomically), so a request that dies mid-flight can never leave a half-written sale or a double-restocked product. The gap is purely the client-side surface, which means the fix is a presentation layer and not a data-integrity rewrite.
- The submit button in orders.blade.php:559-561 is the house pattern worth copying everywhere: `wire:loading.attr="disabled"` + `wire:target="save"` + an inline spinner + `disabled:opacity-50`. The appointments modal's submit (appointments.blade.php:927) and every row action in both files should adopt it verbatim.
- Destructive actions are guarded with translated native confirms (`wire:confirm="{{ __('appointments.delete_confirm') }}"` at appointments.blade.php:1078, orders.blade.php:635) — no new dependency, works on every phone, and the copy goes through the lang files. Extending the same idiom to discarding an unsaved form costs nothing.
- Client-supplied dates are parsed defensively with try/catch and a comment explaining the crash it prevents (dashboard.blade.php:36-43, appointments.blade.php:124-131). This is exactly the right instinct for any `wire:model` value; it just needs to be applied to `$month` as well.

---

## Область: gap: The customer's journey after tapping Confirm was never follo

### [critical · feedback · M] Booking sends the customer nothing — no acknowledgement, no confirmation notice

`confirm()` creates the appointment as `AppointmentStatus::Pending` (booking.blade.php:269) and the success screen promises "we will contact you to confirm" (lang/uz/booking.php:54). But `AppointmentObserver::created()` only dispatches `NewForBarber` to the barber's chat (AppointmentObserver.php:48-56) — there is no client branch at all, and `NotificationTemplates::DEFAULTS` has no client-facing new-booking template (NotificationTemplates.php:21-28). Worse, `updated()` returns early for anything that is not Completed or Cancelled (AppointmentObserver.php:75), so when an admin flips Pending -> Confirmed — the exact promise the success screen makes — the customer is still told nothing. A person who books at 10:00 for Saturday gets a green checkmark on a web page and then total silence until the 30-minute reminder. They cannot tell whether the shop saw the request, which is precisely what produces the no-shows and "did you get my booking?" phone calls.

**Рекомендация.** Add `NewForClient` and `ConfirmedForClient` cases to `App\Telegram\AppointmentNotice`, plus `tg_new_for_client` / `tg_confirmed_for_client` entries in `NotificationTemplates::DEFAULTS` and matching formatter methods in `AppointmentFormatter` (same shape as `cancelledForClient`). In `AppointmentObserver::created()` dispatch `SendAppointmentNotification` to `$appointment->client?->telegram_chat_id` alongside the barber one; in `updated()` add a branch for `AppointmentStatus::Confirmed` mirroring the existing Cancelled branch. Surface both new templates on the Telegram templates admin page so the shop can word them. For clients with no Telegram, fall back to SMS the way `SendUpcomingReminders::notifyClient()` already does (a new approved Eskiz text is needed for that).

**Код:** app/Observers/AppointmentObserver.php:48

### [critical · convenience · M] Customer has no way to cancel or reschedule — plan changes require a phone call

`routes/telegram.php` registers exactly three client entry points — appointments, history, debt (routes/telegram.php:32-35) — and `ClientMenuHandler` implements them as read-only message dumps (ClientMenuHandler.php:17, :44, :71). There are no `onCallbackQueryData` handlers anywhere in the file, so nothing in the bot mutates an appointment. The booking page's only post-confirm action is `reset_flow` ("book again", booking.blade.php:579), which creates a second appointment rather than moving the first. A customer whose plans change therefore either phones the shop or simply does not turn up — and since the shop is not told, the chair sits empty. Note the plumbing for the shop side already exists: `AppointmentObserver::updated()` fires `CancelledForBarber` on any Cancelled transition (AppointmentObserver.php:81-87), so a client-initiated cancel would notify the barber for free.

**Рекомендация.** In `ClientMenuHandler::appointments()`, send each upcoming appointment as its own message with an `InlineKeyboardMarkup` carrying `cancel:{id}` callback data, and register a `$bot->onCallbackQueryData('cancel:(\d+)', ...)` handler in routes/telegram.php. The handler must re-resolve the client via `TelegramLinker::findClientByChat()` and assert `$appointment->client_id === $client->id` before setting `status = AppointmentStatus::Cancelled` (never trust the callback payload alone). Ask for confirmation with a second inline step, and let the existing observer handle notifying the barber. For reschedule, the cheapest version is a button linking back to the booking wizard.

**Код:** app/Telegram/Handlers/ClientMenuHandler.php:17

### [high · clarity · S] Booking page never shows the shop's phone, address, or Telegram bot

The success screen renders a service/price card and a "book again" button (booking.blade.php:568-585) and the public header renders only the logo, language switcher and theme toggle (components/layouts/booking.blade.php:44-60). Neither shows a phone number, an address, or a link to the Telegram bot. The admin settings page already stores all three — `shop_phone`, `shop_address`, `telegram` (admin/settings.blade.php:28-33) — so the data is sitting unused. Combined with the two findings above, this is the trap: the customer has no notification, no self-service cancel, and no number to call. A first-time customer also has no idea where the shop is.

**Рекомендация.** On step 5, below the summary card, render a contact block from settings: `Setting::get('shop_phone')` as an `<a href="tel:...">` styled like the existing outline button (reuse the classes on booking.blade.php:580), `Setting::get('shop_address')` as text, and `Setting::get('telegram')` as a "manage your booking in Telegram" link. Add the phone link to the public header in components/layouts/booking.blade.php too so it is reachable from every step. Add the labels to lang/{uz,ru,kaa}/booking.php under a `success.contact_*` group.

**Код:** resources/views/livewire/pages/booking.blade.php:566

### [high · feedback · M] Shop-side cancellation reaches only Telegram-linked clients; everyone else finds out by showing up

When staff cancels an appointment, `AppointmentObserver::updated()` notifies the client only if `$appointment->client?->telegram_chat_id` is non-null (AppointmentObserver.php:89-97). There is no SMS fallback — unlike `SendUpcomingReminders::notifyClient()`, which explicitly falls back to SMS when the chat id is missing (SendUpcomingReminders.php:61-72). Every customer who books through the web wizard is created via `Client::firstOrCreate()` with no chat id (booking.blade.php:245-248), so the default case for an online booker is silence. If the barber calls in sick, those customers travel to a shop that is not expecting them.

**Рекомендация.** Extract the notify-client ladder from `SendUpcomingReminders::notifyClient()` into a small support class (e.g. `App\Support\ClientNotifier::notify($client, $telegramText, $smsType, $vars)`) and call it from the observer's Cancelled branch instead of dispatching straight to Telegram. Add a `cancellation` entry to `NotificationTemplates::SMS` for all three locales — the class docblock (NotificationTemplates.php:39-45) correctly notes the string must match Eskiz character-for-character, so flag the new text for Eskiz approval before shipping. Gate it behind an `sms_enabled_cancellation` setting following the existing `isEnabledFor()` pattern.

**Код:** app/Observers/AppointmentObserver.php:89

### [high · i18n · L] Bot answers every customer in Russian regardless of the language they booked in

The booking wizard is fully trilingual, but every string a client sees from the bot is a hardcoded Russian literal: the welcome and share-phone prompt (StartHandler.php:20, :26, :32-33), the link outcomes (ContactHandler.php:26, :37-49), the client menu button captions (Keyboards.php:16-20), all four `ClientMenuHandler` replies (ClientMenuHandler.php:34, :61, :85, :102), all five `DEFAULTS` templates (NotificationTemplates.php:23-27), and the dates inside them via `Client::formatRussianDate()` (AppointmentFormatter.php:24, :61). The root cause is that there is nowhere to record a preference: `Client` has no locale in `$fillable` or `casts()` (Client.php:17-35) and no migration adds such a column. A customer who completes the whole wizard in Karakalpak is greeted in Russian by the bot the moment they link.

**Рекомендация.** Add a `locale` column to `clients` (nullable string(5), following the migration-safety skill for the MySQL/SQLite split), add it to `$fillable`, and set it from `app()->getLocale()` in the `Client::firstOrCreate()` call at booking.blade.php:245-248 (and on the create path in `TelegramLinker`). Move the bot strings into `lang/{uz,ru,kaa}/bot.php` and wrap them in `__()`; in each handler, resolve the client first and run the reply inside `App::setLocale($client->locale ?? config('app.locale'))`. Replace `formatRussianDate` in `AppointmentFormatter::clientLine()` with the already-localized `Client::formatLocalizedDate()` (Client.php:55-62).

**Код:** app/Telegram/Handlers/ClientMenuHandler.php:34

### [high · i18n · M] SMS language is one global setting applied to every customer

`NotificationTemplates::smsLocale()` reads a single `sms_locale` setting (NotificationTemplates.php:97-102) and `renderSms()` uses it for every recipient (NotificationTemplates.php:110-120). The admin screen exposes it as one dropdown for the whole shop (admin/sms/settings.blade.php:166-171), and the hint says so plainly: "which language to send all SMS to clients in" (lang/ru/sms.php:30). For a Nukus barbershop with a mixed uz/ru/kaa clientele this is a forced loss: choose Russian and the Karakalpak customers get a reminder they may not read; choose kaa and the Russian speakers do. The information needed to do this right is already known at booking time — the customer picked a language in the wizard header — it is simply thrown away.

**Рекомендация.** Once `clients.locale` exists (previous finding), change `renderSms()` to accept an optional locale argument, and have `SendUpcomingReminders::notifyClient()` / `SendRetentionMessages` pass `$client->locale`. Keep the `sms_locale` setting as the fallback for clients with no recorded preference and relabel it in lang/*/sms.php as "default SMS language" with a hint saying clients who booked online receive their own language. All three locale strings are already approved in `NotificationTemplates::SMS`, so no new Eskiz submission is required.

**Код:** app/Support/NotificationTemplates.php:97

### [high · clarity · S] The price quoted to the customer is the barber's base price, not the per-service price actually booked

Steps 2, 4 and 5 all display `$barber->formattedPrice` (booking.blade.php:377, :471, :575), which is the barber's flat `price` column (Barber.php:88-90). The price actually written onto the appointment is `$barber->priceForService($service->id) ?? $barber->price ?? 0` (booking.blade.php:261), which prefers the `barber_service` pivot price (Barber.php:59-69). Whenever a barber has a per-service price configured — the whole reason that pivot exists — the customer is quoted one number on the confirmation screen and charged another at the till. On top of that, the price block is wrapped in `@if ($this->selectedBarber?->price)` (booking.blade.php:470), so a barber with no base price but a valid per-service price shows the customer no price at all, and step 5 falls back to an unexplained em dash (booking.blade.php:575).

**Рекомендация.** Add a `#[Computed] quotedPrice()` returning `$this->selectedBarber?->priceForService($this->serviceId)` and render that on steps 2 (per-service price for the currently selected service), 4 and 5, formatted with the same `number_format` helper. Drop the `@if ($this->selectedBarber?->price)` guard in favour of a null check on the computed value so the pivot-only case still shows a price.

**Код:** resources/views/livewire/pages/booking.blade.php:575

### [high · clarity · S] Booking offers all 24 hours and lets customers pick times already in the past

`availableSlots()` hardcodes a 00:00-23:00 loop (booking.blade.php:129-135) and `takenSlots()` only removes hours that collide with an existing appointment (booking.blade.php:144-173). Nothing consults the `work_start` / `work_end` settings the shop already fills in (admin/settings.blade.php:30-31, :111-118), and nothing filters out past hours on today's date — the date strip defaults to today (booking.blade.php:46, :401-403). At 18:00 a customer can confirm 09:00 today, or 03:00 tomorrow, and `confirm()` validates only the `HH:MM` shape (booking.blade.php:217). Both bookings land in the barber's schedule as real Pending rows and can only be undone by a phone call — the exact front-desk cost this gap is about.

**Рекомендация.** In `availableSlots()`, bound the loop with `(int) Setting::get('work_start', '09:00')` and `work_end` (parse the hour off the stored `HH:MM`), and when `$this->date` is today, skip slots whose `Carbon::parse($this->date.' '.$time)` is already past. Add a matching `after_or_equal:now` style check inside `confirm()` so a stale browser tab cannot post an expired slot. The existing `booking.datetime.no_slots` string (lang/uz/booking.php:34) already covers the "nothing left today" case.

**Код:** resources/views/livewire/pages/booking.blade.php:130

### [medium · feedback · S] The single reminder is fired from a 60-second window and skipped entirely for near-term bookings

`SendUpcomingReminders` targets `now()->addMinutes(30)` and matches `whereBetween('starts_at', [target-30s, target+30s])` (SendUpcomingReminders.php:22-30). The schedule runs every minute with `withoutOverlapping()` (routes/console.php:14-17), so any minute the run is skipped — overlap, deploy, queue stall, cron hiccup — permanently loses that appointment's reminder, because the next run's window has moved past it. Separately, anyone who books less than 30 minutes before their slot never enters the window at all and gets nothing. Given this is currently the only message a booked customer receives (see the first finding), losing it means the customer heard from the shop exactly zero times.

**Рекомендация.** Widen the query to `->where('starts_at', '>', now())->where('starts_at', '<=', now()->addMinutes(30))`. The `notified_30min = false` filter already in the query plus the `forceFill(['notified_30min' => true])` write at SendUpcomingReminders.php:45 make this idempotent, so a wider window cannot double-send. Consider a second, day-before reminder for appointments booked more than 24 hours out, gated by its own setting following the `sms_enabled_reminder` pattern.

**Код:** app/Console/Commands/SendUpcomingReminders.php:22

### [medium · clarity · S] Success screen promises an SMS reminder that may never be sent

Step 5 states unconditionally that an SMS reminder arrives 30 minutes before the visit (booking.blade.php:566 / lang/uz/booking.php:55). Two things can make that false. If the client is Telegram-linked, `notifyClient()` sends Telegram and no SMS (SendUpcomingReminders.php:61-66) — harmless but inaccurate. If the admin has switched the reminder toggle off (admin/sms/settings.blade.php:50, :179), a non-Telegram client gets nothing whatsoever, and the customer was explicitly told otherwise. Since the reminder is the only touch the customer gets, an unmet promise here converts directly into a no-show.

**Рекомендация.** Reword `booking.success.sms_note` in all three lang files to a conditional statement ("we will remind you before your visit") rather than naming the channel, or read `SmsService::isEnabledFor('reminder')` in the component and only render the note when reminders are actually on. Pair this with the confirmation notice from the first finding so the screen can promise something that always happens.

**Код:** resources/views/livewire/pages/booking.blade.php:566

### [medium · i18n · S] "сум" is hardcoded in Russian on the trilingual booking page and in the bot's debt reply

`Barber::$formattedPrice` appends the literal `' сум'` (Barber.php:89), and that value is rendered on the barber cards, the confirmation summary and the success screen (booking.blade.php:377, :471, :575). So an Uzbek or Karakalpak customer sees a fully translated page with a Cyrillic Russian currency word on the single most important number. `ClientMenuHandler::debt()` does the same (ClientMenuHandler.php:91). The app already has the right idiom — `__('common.currency')` is used on the SMS settings page (admin/sms/settings.blade.php:133).

**Рекомендация.** Change the `formattedPrice` hook in Barber.php (and the equivalents on Appointment/Order if they share the literal) to append `__('common.currency')`, and use the same key in the bot's debt reply once the bot strings are localized. Verify `common.currency` is present in all three lang files.

**Код:** app/Models/Barber.php:89

### [low · consistency · S] SMS settings screen lets admins pick a language but never shows the text customers receive

The dropdown at admin/sms/settings.blade.php:166-171 switches `sms_locale` between three languages, but the actual approved strings live only in `NotificationTemplates::SMS` (NotificationTemplates.php:46-57) and are never rendered anywhere in the admin UI. An admin choosing "Qaraqalpaqsha" has no idea what their customers will read. This is inconsistent with the Telegram templates page, which shows the full editable text of every notification alongside its variable list (lang/uz/telegram.php:27-43).

**Рекомендация.** Under the locale selector, render a read-only preview card showing `NotificationTemplates::SMS['reminder'][$smsLocale]` and `['retention'][$smsLocale]` in the same muted card style used elsewhere on the page, with a short note (new key in lang/*/sms.php) explaining the texts are fixed because Eskiz only delivers pre-approved strings. It re-renders for free on the existing `wire:model.live` binding.

**Код:** resources/views/livewire/pages/admin/sms/settings.blade.php:166

**Сильные стороны области:**
- Phone-first progressive disclosure in the booking wizard is excellent: the name/birth fields stay locked with an explanatory hint until a valid number is entered (booking.blade.php:519-525), the live lookup shows a debounced spinner, a green check and a "client found" line (booking.blade.php:503-515), and `clearAutofill()` prevents a previous client's data from leaking into a new booking (booking.blade.php:90-97). Worth copying to any other public-facing form.
- The confirm button is the model for submit feedback in this app: `@disabled(! $ready)` plus `wire:loading.attr="disabled"` plus distinct submit/submitting labels (booking.blade.php:540-545), backed by server-side IP rate limiting that surfaces as a field error rather than an exception (booking.blade.php:202-208).
- `SendUpcomingReminders::notifyClient()` is the right channel-ladder pattern — prefer Telegram when linked, fall back to SMS otherwise, and only mark `notified_30min` when a send actually succeeded (SendUpcomingReminders.php:39-45, :57-73). Every other client-facing notice in the app (cancellation, confirmation) should be routed through this same ladder instead of dispatching straight to Telegram.

---

## Слепые зоны, найденные критиком покрытия

### Пробел 1

The barber role's entire experience is unreviewed. `RestrictBarberAccess` redirects every barber away from all 15 other admin routes to `/admin/appointments`, and `admin-header.blade.php:37-39` filters the sidebar down to that single link — so for the shop's masters the whole product is one page, yet all 12 appointment findings were written from the admin's point of view. Concretely, in `appointments.blade.php` `$isBarberView` strips the actions column entirely (`@unless ($isBarberView)` at lines 957 and 1047): a barber can see their day but cannot mark a client confirmed, completed or cancelled — every status change requires finding an admin. They also get no earnings view in the web app, although `BarberMenuHandler::earnings()` proves the concept exists in the Telegram bot, so the same person sees more in a chat window than in the CRM. Secondary: the redirect is silent (a deep link from the bot lands them on appointments with no explanation), the desktop chrome still renders a collapse toggle and a scrollable nav for a one-item menu, and the barber column (always themselves) is still rendered in their own table.

*Почему важно:* This is one of the app's three roles and the one used most on phones during a shift. A read-only single page with no actions and no earnings is a product-level UX gap that no per-page finding can surface, and it is invisible unless a reviewer logs in as a barber.

### Пробел 2

There is no failure surface anywhere in the app — the review covered the happy path's missing loading states but never what a phone on bad mobile data actually sees. `resources/js/app.js` is literally a single `//` comment: no `Livewire.hook`, no `request`/`fail` handler, no `wire:offline` or `wire:dirty` anywhere in `resources/`, and `bootstrap/app.php`'s `withExceptions` closure is empty. So a dropped request on the appointments modal or the sale form produces Livewire's stock, untranslated error overlay (or nothing at all), and the half-entered visit is gone. Session expiry compounds it: `SESSION_LIFETIME=120`, `expire_on_close=false`, database driver — a tab parked over lunch 419s on the next tap and the default flow is a browser-native English dialog followed by a reload that discards the form. The dashboard's `wire:poll.60s` (dashboard.blade.php:565) is the opposite failure: when polls start failing the cash-register numbers just quietly go stale with no staleness or offline indicator, which matters because staff read those figures to close the day.

*Почему важно:* The stated environment is staff on phones in a shop; transport failure and session expiry mid-form are routine there, not edge cases. Nothing in ~100 findings addresses error recovery, unsaved-work preservation, staleness, or offline feedback, and the fix is one cross-cutting layer rather than 28 per-page patches.

### Пробел 3

The customer's journey after tapping Confirm was never followed. `AppointmentObserver` only dispatches `NewForBarber`, `CancelledForBarber` and `CancelledForClient` — `NotificationTemplates::DEFAULTS` has no new-booking notice for the client at all, so someone who books online gets a success screen and then silence until a reminder 30 minutes before the visit (`SendUpcomingReminders`). There is also no customer-side cancel or reschedule: `ClientMenuHandler` exposes only appointments/history/debt, so changing plans means phoning a shop whose number the booking page never shows. Language is the third half of this: the booking wizard is trilingual, but `clients` has no locale column, SMS language is one global `sms_locale` setting for every customer (`NotificationTemplates::smsLocale()`), and every Telegram string a client sees is hardcoded Russian (`ClientMenuHandler`, `StartHandler`, the five `DEFAULTS` templates) — a customer who books in Karakalpak is answered in Russian by the bot.

*Почему важно:* The review treated SMS/Telegram as two admin screens and booking as a wizard, so the actual end-to-end customer experience — book, get nothing, cannot cancel, receive a reminder in the wrong language — fell between the modules. No-shows and phone calls to the front desk are the direct business cost, and it is the one flow the shop's paying customers see.


---

## План работ → issues

| Приоритет | Issues |
|-----------|--------|
| **P0 — деньги и корректность** | #47 цена брони · #48 слоты/двойная бронь · #49 PII по телефону · #50 подмена клиента в записи · #51 молчаливое удаление · #52 месяц дашборда · #53 переплата долга |
| **P1 — пагинация и масштаб** | #54 инфраструктура пагинатора (пререквизит) · #55 клиенты · #56 долги · #57 карточка клиента · #58 telegram-привязки · #59 товары · #60 поиск в SMS-истории |
| **P2 — обратная связь и удобство** | #61 свип loading/disabled · #62 подтверждения записей · #63 автовремя конца · #64 навигация по датам · #65 выручка ≠ получено · #66 производительность дашборда · #67 валюта · #68 сайдбар · #69 мобильные таблицы · #70 баланс SMS · #71 рассылка Telegram · #72 URL-шаги брони · #73 форма мастера |
| **P3 — стратегические пробелы** | #74 роль мастера · #75 отказоустойчивость · #76 путь клиента |
| **P4 — полировка** | #77 зонтичный бэклог medium/low |

Рекомендуемый порядок: P0 → P1 (после #54 — механически) → P2 → P3 (каждый — мини-проект) → P4 порциями.
