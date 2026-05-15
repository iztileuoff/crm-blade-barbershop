<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    public string $date = '';
    public string $month = '';
    public string $activeTab = 'day';

    public function mount(): void
    {
        $now = Carbon::now('Asia/Tashkent');
        $this->date = $now->toDateString();
        $this->month = $now->format('Y-m');
    }

    #[Computed]
    public function dateString(): string
    {
        $months = [
            1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
            5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
            9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
        ];

        try {
            $d = Carbon::parse($this->date);
        } catch (\Exception $e) {
            $d = Carbon::now('Asia/Tashkent');
        }

        return "{$d->day} {$months[$d->month]} {$d->year}";
    }

    #[Computed]
    public function monthString(): string
    {
        $months = [
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
            5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
            9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
        ];

        try {
            $d = Carbon::parse($this->month.'-01');
        } catch (\Exception $e) {
            $d = Carbon::now('Asia/Tashkent');
        }

        return "{$months[$d->month]} {$d->year}";
    }

    // ─── Daily computed ───────────────────────────────────────────────────────

    #[Computed]
    public function appointments(): Collection
    {
        return Appointment::query()
            ->with(['barber', 'services'])
            ->whereDate('starts_at', $this->date ?: today('Asia/Tashkent'))
            ->get();
    }

    #[Computed]
    public function totalAppointments(): int
    {
        return $this->appointments->count();
    }

    #[Computed]
    public function confirmedAmount(): int
    {
        return (int) $this->appointments
            ->whereIn('status', [AppointmentStatus::Completed, AppointmentStatus::Confirmed])
            ->sum(fn ($appointment) => (int) ($appointment->price ?? $appointment->barber?->price ?? 0));
    }

    #[Computed]
    public function pendingCount(): int
    {
        return $this->appointments->where('status', AppointmentStatus::Pending)->count();
    }

    #[Computed]
    public function completedCount(): int
    {
        return $this->appointments->where('status', AppointmentStatus::Completed)->count();
    }

    #[Computed]
    public function cancelledCount(): int
    {
        return $this->appointments->where('status', AppointmentStatus::Cancelled)->count();
    }

    #[Computed]
    public function productOrders(): Collection
    {
        return Order::query()
            ->with(['client', 'items.product'])
            ->whereDate('created_at', $this->date ?: today('Asia/Tashkent'))
            ->get();
    }

    #[Computed]
    public function productSalesAmount(): int
    {
        return (int) $this->productOrders->sum('total_price');
    }

    #[Computed]
    public function totalRevenue(): int
    {
        return $this->confirmedAmount + $this->productSalesAmount;
    }

    #[Computed]
    public function barberStats(): Collection
    {
        $byBarber = $this->appointments->groupBy('barber_id');

        return Barber::active()
            ->orderBy('name')
            ->get()
            ->map(function (Barber $barber) use ($byBarber) {
                $items = $byBarber->get($barber->id, collect());

                $revenue = (int) $items
                    ->whereIn('status', [AppointmentStatus::Completed, AppointmentStatus::Confirmed])
                    ->sum(fn ($appointment) => (int) ($appointment->price ?? $barber->price ?? 0));

                $cancelledCount = $items->where('status', AppointmentStatus::Cancelled)->count();
                $salary = (int) round($revenue * $barber->salary_percent / 100);

                return (object) [
                    'id' => $barber->id,
                    'name' => $barber->name,
                    'photoUrl' => $barber->photoUrl,
                    'count' => $items->count(),
                    'cancelled_count' => $cancelledCount,
                    'revenue' => $revenue,
                    'salary' => $salary,
                    'salaryPercent' => $barber->salary_percent,
                    'formattedRevenue' => number_format($revenue, 0, '.', ' ').' сум',
                    'formattedSalary' => number_format($salary, 0, '.', ' ').' сум',
                ];
            });
    }

    // ─── Monthly computed ─────────────────────────────────────────────────────

    #[Computed]
    public function monthlyAppointments(): Collection
    {
        $start = Carbon::parse($this->month.'-01', 'Asia/Tashkent')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return Appointment::query()
            ->with(['barber'])
            ->whereIn('status', [AppointmentStatus::Confirmed, AppointmentStatus::Completed])
            ->whereBetween('starts_at', [$start, $end])
            ->get();
    }

    #[Computed]
    public function monthlyOrders(): Collection
    {
        $start = Carbon::parse($this->month.'-01', 'Asia/Tashkent')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return Order::query()
            ->whereBetween('created_at', [$start, $end])
            ->get(['created_at', 'total_price']);
    }

    #[Computed]
    public function monthlyServiceRevenue(): int
    {
        return (int) $this->monthlyAppointments->sum('price');
    }

    #[Computed]
    public function monthlyProductRevenue(): int
    {
        return (int) $this->monthlyOrders->sum('total_price');
    }

    #[Computed]
    public function monthlyTotalRevenue(): int
    {
        return $this->monthlyServiceRevenue + $this->monthlyProductRevenue;
    }

    #[Computed]
    public function monthlyBarberStats(): Collection
    {
        $byBarber = $this->monthlyAppointments->groupBy('barber_id');

        return Barber::active()
            ->orderBy('name')
            ->get()
            ->map(function (Barber $barber) use ($byBarber) {
                $items = $byBarber->get($barber->id, collect());
                $revenue = (int) $items->sum('price');
                $salary = (int) round($revenue * $barber->salary_percent / 100);

                return (object) [
                    'id' => $barber->id,
                    'name' => $barber->name,
                    'photoUrl' => $barber->photoUrl,
                    'count' => $items->count(),
                    'revenue' => $revenue,
                    'salary' => $salary,
                    'salaryPercent' => $barber->salary_percent,
                    'formattedRevenue' => number_format($revenue, 0, '.', ' ').' сум',
                    'formattedSalary' => number_format($salary, 0, '.', ' ').' сум',
                ];
            });
    }

    #[Computed]
    public function monthlyTotalSalary(): int
    {
        return (int) $this->monthlyBarberStats->sum('salary');
    }

    #[Computed]
    public function companyProfit(): int
    {
        return $this->monthlyTotalRevenue - $this->monthlyTotalSalary;
    }

    #[Computed]
    public function dailyChartData(): array
    {
        $start = Carbon::parse($this->month.'-01', 'Asia/Tashkent')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $appointments = Appointment::query()
            ->whereIn('status', [AppointmentStatus::Confirmed, AppointmentStatus::Completed])
            ->whereBetween('starts_at', [$start, $end])
            ->get(['starts_at', 'price']);

        $orders = Order::query()
            ->whereBetween('created_at', [$start, $end])
            ->get(['created_at', 'total_price']);

        $days = [];
        for ($day = 1; $day <= $end->day; $day++) {
            $date = $this->month.'-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT);

            $serviceRev = (int) $appointments
                ->filter(fn ($a) => Carbon::parse($a->starts_at)->toDateString() === $date)
                ->sum('price');

            $productRev = (int) $orders
                ->filter(fn ($o) => Carbon::parse($o->created_at)->toDateString() === $date)
                ->sum('total_price');

            $days[] = [
                'day' => $day,
                'date' => $date,
                'service' => $serviceRev,
                'product' => $productRev,
                'total' => $serviceRev + $productRev,
            ];
        }

        return $days;
    }

    public function formatSum(int $value): string
    {
        return number_format($value, 0, '.', ' ').' сум';
    }
}; ?>

<div class="animate-fade-in-up" wire:poll.60s>
    {{-- Header --}}
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-extrabold tracking-tight text-white">Касса</h1>
            <p class="mt-1 text-sm text-white/40">
                @if ($activeTab === 'day')
                    Сводка за день — {{ $this->dateString }}
                @else
                    Сводка за месяц — {{ $this->monthString }}
                @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            {{-- Tab toggle --}}
            <div class="flex items-center rounded-xl border border-white/10 bg-white/[0.04] p-1">
                <button
                    wire:click="$set('activeTab', 'day')"
                    @class([
                        'rounded-lg px-4 py-1.5 text-xs font-bold transition-all',
                        'bg-amber-500 text-white shadow' => $activeTab === 'day',
                        'text-white/40 hover:text-white/70' => $activeTab !== 'day',
                    ])
                >
                    День
                </button>
                <button
                    wire:click="$set('activeTab', 'month')"
                    @class([
                        'rounded-lg px-4 py-1.5 text-xs font-bold transition-all',
                        'bg-amber-500 text-white shadow' => $activeTab === 'month',
                        'text-white/40 hover:text-white/70' => $activeTab !== 'month',
                    ])
                >
                    Месяц
                </button>
            </div>

            {{-- Date / Month picker --}}
            @if ($activeTab === 'day')
                <input
                    type="date"
                    wire:model.live="date"
                    class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm text-white shadow-sm transition-colors focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:[color-scheme:dark]"
                >
            @else
                <input
                    type="month"
                    wire:model.live="month"
                    class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm text-white shadow-sm transition-colors focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:[color-scheme:dark]"
                >
            @endif

            <div class="flex items-center gap-2 rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-3 py-1.5 text-xs font-bold text-emerald-400">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                </span>
                Обновление каждые 60 сек
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- DAY TAB                                                            --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    @if ($activeTab === 'day')

        {{-- Top Stats --}}
        <div class="mb-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Total revenue --}}
            <div class="sm:col-span-2 overflow-hidden rounded-2xl border border-violet-500/20 bg-gradient-to-br from-violet-500/10 to-violet-500/[0.02] p-6 shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-widest text-violet-400/70">Общий оборот</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/15 text-violet-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
                    </div>
                </div>
                <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->formatSum($this->totalRevenue) }}</div>
                <div class="mt-1 flex items-center gap-4 text-xs">
                    <span class="text-emerald-400/70">Услуги: {{ $this->formatSum($this->confirmedAmount) }}</span>
                    <span class="text-amber-400/70">Товары: {{ $this->formatSum($this->productSalesAmount) }}</span>
                </div>
            </div>

            {{-- Product sales --}}
            <div class="overflow-hidden rounded-2xl border border-amber-500/20 bg-gradient-to-br from-amber-500/10 to-amber-500/[0.02] p-6 shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-widest text-amber-400/70">Продажи товаров</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/15 text-amber-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z" /></svg>
                    </div>
                </div>
                <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->formatSum($this->productSalesAmount) }}</div>
                <div class="mt-1 text-xs text-amber-400/60">{{ $this->productOrders->count() }} продаж за день</div>
            </div>

            {{-- Appointments amount --}}
            <div class="overflow-hidden rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-500/10 to-emerald-500/[0.02] p-6 shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-widest text-emerald-400/70">Услуги в кассе</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/15 text-emerald-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                    </div>
                </div>
                <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->formatSum($this->confirmedAmount) }}</div>
                <div class="mt-1 text-xs text-emerald-400/60">Подтверждённые и завершённые</div>
            </div>
        </div>

        {{-- Appointment Stats Row --}}
        <div class="mb-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Total appointments --}}
            <div class="overflow-hidden rounded-2xl border border-white/[0.06] bg-gradient-to-br from-white/[0.04] to-white/[0.01] p-6 shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-widest text-white/40">Записей за день</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10 text-amber-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                    </div>
                </div>
                <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->totalAppointments }}</div>
                <div class="mt-1 text-xs text-white/30">Все записи на день</div>
            </div>

            {{-- Pending --}}
            <div class="overflow-hidden rounded-2xl border border-amber-500/20 bg-gradient-to-br from-amber-500/10 to-amber-500/[0.02] p-6 shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-widest text-amber-400/70">Ожидают</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/15 text-amber-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    </div>
                </div>
                <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->pendingCount }}</div>
                <div class="mt-1 text-xs text-amber-400/60">Требуют подтверждения</div>
            </div>

            {{-- Completed --}}
            <div class="overflow-hidden rounded-2xl border border-white/[0.06] bg-gradient-to-br from-white/[0.04] to-white/[0.01] p-6 shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-widest text-white/40">Завершено</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/10 text-blue-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                    </div>
                </div>
                <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->completedCount }}</div>
                <div class="mt-1 text-xs text-white/30">Закрытые визиты</div>
            </div>

            {{-- Cancelled --}}
            <div class="overflow-hidden rounded-2xl border border-rose-500/20 bg-gradient-to-br from-rose-500/10 to-rose-500/[0.02] p-6 shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-widest text-rose-400/70">Отменено</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-500/15 text-rose-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </div>
                </div>
                <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->cancelledCount }}</div>
                <div class="mt-1 text-xs text-rose-400/60">Отмененные записи</div>
            </div>
        </div>

        {{-- Product sales summary --}}
        @if ($this->productOrders->isNotEmpty())
            <div class="mb-8 overflow-hidden rounded-2xl border border-white/[0.06] bg-white/[0.03] shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between border-b border-white/[0.06] bg-white/[0.03] px-6 py-4">
                    <div>
                        <h3 class="text-sm font-bold text-white">Продажи товаров за день</h3>
                        <p class="mt-0.5 text-xs text-white/30">Розничные продажи из склада</p>
                    </div>
                    <a href="{{ route('admin.orders') }}" class="text-xs font-bold text-amber-400/70 transition hover:text-amber-400">
                        Все продажи →
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-white/[0.06] bg-white/[0.03] text-xs font-bold uppercase tracking-wider text-white/30">
                                <th class="px-6 py-4">Время</th>
                                <th class="px-6 py-4">Клиент</th>
                                <th class="px-6 py-4">Товары</th>
                                <th class="px-6 py-4 text-right">Сумма</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.04]">
                            @foreach ($this->productOrders as $order)
                                <tr class="transition-colors hover:bg-white/[0.02]">
                                    <td class="whitespace-nowrap px-6 py-4 font-bold text-white">
                                        {{ $order->created_at->format('H:i') }}
                                    </td>
                                    <td class="px-6 py-4 text-white/60">
                                        {{ $order->client?->name ?? '—' }}
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($order->items as $item)
                                                <span class="inline-flex items-center rounded-full bg-white/[0.06] px-2.5 py-1 text-xs text-white/60">
                                                    {{ $item->product?->name ?? '—' }}
                                                    <span class="ml-1 font-bold text-white/40">×{{ $item->quantity }}</span>
                                                </span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right font-extrabold text-amber-400 tabular-nums">
                                        {{ $order->formattedTotal }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-white/[0.06] bg-white/[0.03] text-xs font-bold uppercase tracking-wider text-white/40">
                                <td class="px-6 py-4" colspan="3">Итого товары</td>
                                <td class="px-6 py-4 text-right text-amber-400">{{ $this->formatSum($this->productSalesAmount) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @endif

        {{-- Barber Performance (daily) --}}
        <div class="overflow-hidden rounded-2xl border border-white/[0.06] bg-white/[0.03] shadow-xl backdrop-blur-md">
            <div class="flex items-center justify-between border-b border-white/[0.06] bg-white/[0.03] px-6 py-4">
                <div>
                    <h3 class="text-sm font-bold text-white">Производительность мастеров</h3>
                    <p class="mt-0.5 text-xs text-white/30">Записи и выручка за день</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-white/[0.06] bg-white/[0.03] text-xs font-bold uppercase tracking-wider text-white/30">
                            <th class="px-6 py-4">Имя</th>
                            <th class="px-6 py-4 text-center">Записей за день</th>
                            <th class="px-6 py-4 text-center">Отменено</th>
                            <th class="px-6 py-4 text-right">Выручка</th>
                            <th class="px-6 py-4 text-right">ЗП</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.04]">
                        @forelse ($this->barberStats as $stat)
                            <tr class="transition-colors hover:bg-white/[0.02]">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        @if ($stat->photoUrl)
                                            <img src="{{ $stat->photoUrl }}" class="h-9 w-9 rounded-full object-cover ring-1 ring-white/10">
                                        @else
                                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-white/[0.06] text-xs font-bold text-white/40">
                                                {{ mb_substr($stat->name, 0, 1) }}
                                            </div>
                                        @endif
                                        <div class="font-bold text-white">{{ $stat->name }}</div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span @class([
                                        'inline-flex min-w-[2.5rem] items-center justify-center rounded-full px-3 py-1 text-xs font-bold',
                                        'bg-amber-500/10 text-amber-400' => $stat->count > 0,
                                        'bg-white/[0.04] text-white/30' => $stat->count === 0,
                                    ])>
                                        {{ $stat->count }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span @class([
                                        'inline-flex min-w-[2.5rem] items-center justify-center rounded-full px-3 py-1 text-xs font-bold',
                                        'bg-rose-500/10 text-rose-400' => $stat->cancelled_count > 0,
                                        'bg-white/[0.04] text-white/30' => $stat->cancelled_count === 0,
                                    ])>
                                        {{ $stat->cancelled_count }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <span @class([
                                        'font-extrabold tabular-nums',
                                        'text-emerald-400' => $stat->revenue > 0,
                                        'text-white/30' => $stat->revenue === 0,
                                    ])>
                                        {{ $stat->formattedRevenue }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <span @class([
                                        'font-extrabold tabular-nums',
                                        'text-amber-400' => $stat->salary > 0,
                                        'text-white/30' => $stat->salary === 0,
                                    ])>
                                        {{ $stat->formattedSalary }}
                                    </span>
                                    <div class="mt-0.5 text-[10px] text-white/25">{{ $stat->salaryPercent }}%</div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-white/20">Активные мастера не найдены</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($this->barberStats->isNotEmpty())
                        <tfoot>
                            <tr class="border-t border-white/[0.06] bg-white/[0.03] text-xs font-bold uppercase tracking-wider text-white/40">
                                <td class="px-6 py-4">Итого</td>
                                <td class="px-6 py-4 text-center text-white">{{ $this->barberStats->sum('count') }}</td>
                                <td class="px-6 py-4 text-center text-white">{{ $this->barberStats->sum('cancelled_count') }}</td>
                                <td class="px-6 py-4 text-right text-emerald-400">{{ $this->formatSum((int) $this->barberStats->sum('revenue')) }}</td>
                                <td class="px-6 py-4 text-right text-amber-400">{{ $this->formatSum((int) $this->barberStats->sum('salary')) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

    @endif

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- MONTH TAB                                                          --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    @if ($activeTab === 'month')

        {{-- Monthly KPI cards --}}
        <div class="mb-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {{-- Total monthly revenue --}}
            <div class="overflow-hidden rounded-2xl border border-violet-500/20 bg-gradient-to-br from-violet-500/10 to-violet-500/[0.02] p-6 shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-widest text-violet-400/70">Оборот за месяц</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-500/15 text-violet-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
                    </div>
                </div>
                <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->formatSum($this->monthlyTotalRevenue) }}</div>
                <div class="mt-1 flex items-center gap-4 text-xs">
                    <span class="text-emerald-400/70">Услуги: {{ $this->formatSum($this->monthlyServiceRevenue) }}</span>
                    <span class="text-amber-400/70">Товары: {{ $this->formatSum($this->monthlyProductRevenue) }}</span>
                </div>
            </div>

            {{-- Monthly service revenue --}}
            <div class="overflow-hidden rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-500/10 to-emerald-500/[0.02] p-6 shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-widest text-emerald-400/70">Услуги за месяц</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/15 text-emerald-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" /></svg>
                    </div>
                </div>
                <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->formatSum($this->monthlyServiceRevenue) }}</div>
                <div class="mt-1 text-xs text-emerald-400/60">{{ $this->monthlyAppointments->count() }} завершённых записей</div>
            </div>

            {{-- Monthly product revenue --}}
            <div class="overflow-hidden rounded-2xl border border-amber-500/20 bg-gradient-to-br from-amber-500/10 to-amber-500/[0.02] p-6 shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-widest text-amber-400/70">Товары за месяц</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/15 text-amber-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z" /></svg>
                    </div>
                </div>
                <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->formatSum($this->monthlyProductRevenue) }}</div>
                <div class="mt-1 text-xs text-amber-400/60">{{ $this->monthlyOrders->count() }} продаж</div>
            </div>
        </div>

        {{-- Salary & Profit row --}}
        <div class="mb-8 grid gap-5 sm:grid-cols-2">
            {{-- Total salary --}}
            <div class="overflow-hidden rounded-2xl border border-blue-500/20 bg-gradient-to-br from-blue-500/10 to-blue-500/[0.02] p-6 shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-widest text-blue-400/70">ЗП мастеров за месяц</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/15 text-blue-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                    </div>
                </div>
                <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->formatSum($this->monthlyTotalSalary) }}</div>
                <div class="mt-1 text-xs text-blue-400/60">Выплаты всем активным мастерам</div>
            </div>

            {{-- Company profit --}}
            <div @class([
                'overflow-hidden rounded-2xl border p-6 shadow-xl backdrop-blur-md',
                'border-emerald-500/20 bg-gradient-to-br from-emerald-500/10 to-emerald-500/[0.02]' => $this->companyProfit >= 0,
                'border-rose-500/20 bg-gradient-to-br from-rose-500/10 to-rose-500/[0.02]' => $this->companyProfit < 0,
            ])>
                <div class="flex items-center justify-between">
                    <span @class([
                        'text-xs font-bold uppercase tracking-widest',
                        'text-emerald-400/70' => $this->companyProfit >= 0,
                        'text-rose-400/70' => $this->companyProfit < 0,
                    ])>Прибыль компании</span>
                    <div @class([
                        'flex h-10 w-10 items-center justify-center rounded-xl',
                        'bg-emerald-500/15 text-emerald-400' => $this->companyProfit >= 0,
                        'bg-rose-500/15 text-rose-400' => $this->companyProfit < 0,
                    ])>
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941" /></svg>
                    </div>
                </div>
                <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->formatSum(abs($this->companyProfit)) }}</div>
                <div @class([
                    'mt-1 text-xs',
                    'text-emerald-400/60' => $this->companyProfit >= 0,
                    'text-rose-400/60' => $this->companyProfit < 0,
                ])>
                    {{ $this->companyProfit >= 0 ? 'Оборот минус зарплаты мастеров' : 'Расходы превышают доход' }}
                </div>
            </div>
        </div>

        {{-- Daily Revenue Chart --}}
        <div class="mb-8 overflow-hidden rounded-2xl border border-white/[0.06] bg-white/[0.03] shadow-xl backdrop-blur-md">
            <div class="border-b border-white/[0.06] bg-white/[0.03] px-6 py-4">
                <h3 class="text-sm font-bold text-white">Выручка по дням</h3>
                <p class="mt-0.5 text-xs text-white/30">{{ $this->monthString }} — динамика услуг и продаж товаров</p>
            </div>
            <div class="px-6 py-5">
                {{-- Legend --}}
                <div class="mb-4 flex items-center gap-5 text-xs text-white/50">
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block h-2.5 w-2.5 rounded-sm bg-emerald-500/70"></span>
                        Услуги
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="inline-block h-2.5 w-2.5 rounded-sm bg-amber-500/60"></span>
                        Товары
                    </span>
                </div>

                @php
                    $chartData = $this->dailyChartData;
                    $maxVal = collect($chartData)->max('total') ?: 1;
                    $numDays = count($chartData);
                    $svgH = 150;
                    $svgW = 700;
                    $xStep = $numDays > 0 ? $svgW / $numDays : $svgW;
                    $barW = max(4, $xStep - 4);
                @endphp

                <svg viewBox="0 0 {{ $svgW }} {{ $svgH + 28 }}" class="w-full" xmlns="http://www.w3.org/2000/svg">
                    {{-- Horizontal grid lines --}}
                    @foreach ([0.25, 0.5, 0.75, 1.0] as $ratio)
                        <line
                            x1="0" y1="{{ round($svgH - $ratio * $svgH, 1) }}"
                            x2="{{ $svgW }}" y2="{{ round($svgH - $ratio * $svgH, 1) }}"
                            stroke="rgba(255,255,255,0.04)" stroke-width="1"
                        />
                    @endforeach

                    {{-- Bars --}}
                    @foreach ($chartData as $i => $d)
                        @php
                            $bx = round($i * $xStep + ($xStep - $barW) / 2, 1);
                            $bw = round($barW, 1);
                            $totalH = round($maxVal > 0 ? ($d['total'] / $maxVal) * $svgH : 0, 1);
                            $serviceH = round($maxVal > 0 ? ($d['service'] / $maxVal) * $svgH : 0, 1);
                            $barY = round($svgH - $totalH, 1);
                        @endphp

                        @if ($d['total'] > 0)
                            {{-- Full bar in amber (products base) --}}
                            <rect
                                x="{{ $bx }}"
                                y="{{ $barY }}"
                                width="{{ $bw }}"
                                height="{{ $totalH }}"
                                fill="#f59e0b"
                                fill-opacity="0.65"
                                rx="2"
                            >
                                <title>{{ $d['date'] }}: Товары {{ number_format($d['product'], 0, '.', ' ') }} сум</title>
                            </rect>
                            {{-- Service overlay (emerald) from top --}}
                            @if ($serviceH > 0)
                                <rect
                                    x="{{ $bx }}"
                                    y="{{ $barY }}"
                                    width="{{ $bw }}"
                                    height="{{ $serviceH }}"
                                    fill="#10b981"
                                    fill-opacity="0.75"
                                    rx="2"
                                >
                                    <title>{{ $d['date'] }}: Услуги {{ number_format($d['service'], 0, '.', ' ') }} сум</title>
                                </rect>
                            @endif
                        @else
                            <rect x="{{ $bx }}" y="{{ $svgH - 2 }}" width="{{ $bw }}" height="2" fill="rgba(255,255,255,0.04)" rx="1" />
                        @endif

                        {{-- Day label --}}
                        <text
                            x="{{ round($i * $xStep + $xStep / 2, 1) }}"
                            y="{{ $svgH + 18 }}"
                            text-anchor="middle"
                            font-size="9"
                            fill="rgba(255,255,255,0.25)"
                            font-family="sans-serif"
                        >{{ $d['day'] }}</text>
                    @endforeach
                </svg>

                <div class="mt-2 text-right text-[10px] text-white/20">
                    Макс. за день: {{ $this->formatSum((int) collect($this->dailyChartData)->max('total')) }}
                </div>
            </div>
        </div>

        {{-- Monthly Barber Stats --}}
        <div class="overflow-hidden rounded-2xl border border-white/[0.06] bg-white/[0.03] shadow-xl backdrop-blur-md">
            <div class="flex items-center justify-between border-b border-white/[0.06] bg-white/[0.03] px-6 py-4">
                <div>
                    <h3 class="text-sm font-bold text-white">Зарплата мастеров за месяц</h3>
                    <p class="mt-0.5 text-xs text-white/30">{{ $this->monthString }} — выручка и начисления</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-white/[0.06] bg-white/[0.03] text-xs font-bold uppercase tracking-wider text-white/30">
                            <th class="px-6 py-4">Мастер</th>
                            <th class="px-6 py-4 text-center">Записей</th>
                            <th class="px-6 py-4 text-right">Выручка</th>
                            <th class="px-6 py-4 text-right">% ЗП</th>
                            <th class="px-6 py-4 text-right">ЗП к выплате</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.04]">
                        @forelse ($this->monthlyBarberStats as $stat)
                            <tr class="transition-colors hover:bg-white/[0.02]">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        @if ($stat->photoUrl)
                                            <img src="{{ $stat->photoUrl }}" class="h-9 w-9 rounded-full object-cover ring-1 ring-white/10">
                                        @else
                                            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-white/[0.06] text-xs font-bold text-white/40">
                                                {{ mb_substr($stat->name, 0, 1) }}
                                            </div>
                                        @endif
                                        <div class="font-bold text-white">{{ $stat->name }}</div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span @class([
                                        'inline-flex min-w-[2.5rem] items-center justify-center rounded-full px-3 py-1 text-xs font-bold',
                                        'bg-amber-500/10 text-amber-400' => $stat->count > 0,
                                        'bg-white/[0.04] text-white/30' => $stat->count === 0,
                                    ])>
                                        {{ $stat->count }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <span @class([
                                        'font-extrabold tabular-nums',
                                        'text-emerald-400' => $stat->revenue > 0,
                                        'text-white/30' => $stat->revenue === 0,
                                    ])>
                                        {{ $stat->formattedRevenue }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <span class="text-xs font-bold text-white/40">{{ $stat->salaryPercent }}%</span>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <span @class([
                                        'font-extrabold tabular-nums',
                                        'text-blue-400' => $stat->salary > 0,
                                        'text-white/30' => $stat->salary === 0,
                                    ])>
                                        {{ $stat->formattedSalary }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-white/20">Активные мастера не найдены</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($this->monthlyBarberStats->isNotEmpty())
                        <tfoot>
                            <tr class="border-t border-white/[0.06] bg-white/[0.03] text-xs font-bold uppercase tracking-wider text-white/40">
                                <td class="px-6 py-4">Итого</td>
                                <td class="px-6 py-4 text-center text-white">{{ $this->monthlyBarberStats->sum('count') }}</td>
                                <td class="px-6 py-4 text-right text-emerald-400">{{ $this->formatSum((int) $this->monthlyBarberStats->sum('revenue')) }}</td>
                                <td class="px-6 py-4"></td>
                                <td class="px-6 py-4 text-right text-blue-400">{{ $this->formatSum($this->monthlyTotalSalary) }}</td>
                            </tr>
                            <tr class="border-t border-white/[0.06] bg-white/[0.02] text-xs font-bold uppercase tracking-wider">
                                <td class="px-6 py-4 text-white/40" colspan="3">Прибыль компании (оборот − ЗП)</td>
                                <td class="px-6 py-4"></td>
                                <td @class([
                                    'px-6 py-4 text-right font-extrabold tabular-nums',
                                    'text-emerald-400' => $this->companyProfit >= 0,
                                    'text-rose-400' => $this->companyProfit < 0,
                                ])>
                                    {{ $this->companyProfit < 0 ? '−' : '' }}{{ $this->formatSum(abs($this->companyProfit)) }}
                                </td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

    @endif
</div>
