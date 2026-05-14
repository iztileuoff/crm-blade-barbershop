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

    public function mount(): void
    {
        $this->date = Carbon::now('Asia/Tashkent')->toDateString();
    }

    #[Computed]
    public function dateString(): string
    {
        try {
            return Carbon::parse($this->date)->translatedFormat('d F Y, l');
        } catch (\Exception $e) {
            return Carbon::now('Asia/Tashkent')->translatedFormat('d F Y, l');
        }
    }

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
        return $this->appointments
            ->where('status', AppointmentStatus::Pending)
            ->count();
    }

    #[Computed]
    public function completedCount(): int
    {
        return $this->appointments
            ->where('status', AppointmentStatus::Completed)
            ->count();
    }

    #[Computed]
    public function cancelledCount(): int
    {
        return $this->appointments
            ->where('status', AppointmentStatus::Cancelled)
            ->count();
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

    public function formatSum(int $value): string
    {
        return number_format($value, 0, '.', ' ').' сум';
    }
}; ?>

<div class="animate-fade-in-up" wire:poll.60s>
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-extrabold tracking-tight text-white">Касса</h1>
            <p class="mt-1 text-sm text-white/40">Сводка за день — {{ $this->dateString }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-4">
            <input 
                type="date" 
                wire:model.live="date" 
                class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm text-white shadow-sm transition-colors focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:[color-scheme:dark]"
            >
            <div class="flex items-center gap-2 rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-3 py-1.5 text-xs font-bold text-emerald-400">
                <span class="relative flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                </span>
                Обновление каждые 60 сек
            </div>
        </div>
    </div>

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

    {{-- Barber Performance --}}
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
</div>
