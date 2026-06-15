<?php

use App\Models\Appointment;
use App\Models\Order;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    public string $tab = 'all';

    public ?int $payingAppointmentId = null;

    public ?int $payingOrderId = null;

    public ?int $payAmount = null;

    #[Computed]
    public function appointmentDebts(): Collection
    {
        return Appointment::query()
            ->with(['client', 'barber', 'services'])
            ->where('debt_amount', '>', 0)
            ->orderByDesc('starts_at')
            ->get();
    }

    #[Computed]
    public function orderDebts(): Collection
    {
        return Order::query()
            ->with(['client', 'items.product'])
            ->where('debt_amount', '>', 0)
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function totalAppointmentDebt(): int
    {
        return (int) $this->appointmentDebts->sum('debt_amount');
    }

    #[Computed]
    public function totalOrderDebt(): int
    {
        return (int) $this->orderDebts->sum('debt_amount');
    }

    #[Computed]
    public function grandTotal(): int
    {
        return $this->totalAppointmentDebt + $this->totalOrderDebt;
    }

    public function openPayAppointment(int $id): void
    {
        $this->payingAppointmentId = $id;
        $this->payingOrderId = null;
        $this->payAmount = Appointment::find($id)?->debt_amount;
    }

    public function openPayOrder(int $id): void
    {
        $this->payingOrderId = $id;
        $this->payingAppointmentId = null;
        $this->payAmount = Order::find($id)?->debt_amount;
    }

    public function cancelPay(): void
    {
        $this->payingAppointmentId = null;
        $this->payingOrderId = null;
        $this->payAmount = null;
    }

    public function payAppointmentDebt(): void
    {
        $appointment = Appointment::findOrFail($this->payingAppointmentId);
        $pay = min((int) ($this->payAmount ?? 0), (int) $appointment->debt_amount);

        if ($pay <= 0) {
            $this->addError('payAmount', 'Введите сумму оплаты.');
            return;
        }

        $remaining = max(0, (int) $appointment->debt_amount - $pay);
        $appointment->update(['debt_amount' => $remaining ?: null]);

        unset($this->appointmentDebts);
        $this->cancelPay();
    }

    public function payOrderDebt(): void
    {
        $order = Order::findOrFail($this->payingOrderId);
        $pay = min((int) ($this->payAmount ?? 0), (int) $order->debt_amount);

        if ($pay <= 0) {
            $this->addError('payAmount', 'Введите сумму оплаты.');
            return;
        }

        $remaining = max(0, (int) $order->debt_amount - $pay);
        $order->update(['debt_amount' => $remaining ?: null]);

        unset($this->orderDebts);
        $this->cancelPay();
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">Долги</h1>
            <p class="mt-1 text-sm text-content/40">Неоплаченные записи и продажи</p>
        </div>
    </div>

    {{-- Summary cards --}}
    <div class="mb-8 grid gap-4 sm:grid-cols-3">
        <div class="overflow-hidden rounded-2xl border border-danger/20 bg-gradient-to-br from-danger/10 to-danger/[0.02] p-5 backdrop-blur-md">
            <div class="text-xs font-bold uppercase tracking-widest text-danger/70">Всего долгов</div>
            <div class="mt-2 font-display text-3xl font-bold tabular-nums text-content">{{ number_format($this->grandTotal, 0, '.', ' ') }} сум</div>
            <div class="mt-1 text-xs text-danger/50">{{ $this->appointmentDebts->count() + $this->orderDebts->count() }} позиций</div>
        </div>
        <div class="overflow-hidden rounded-2xl border border-brass/20 bg-gradient-to-br from-brass/10 to-brass/[0.02] p-5 backdrop-blur-md">
            <div class="text-xs font-bold uppercase tracking-widest text-brass-ink/70">По записям</div>
            <div class="mt-2 font-display text-3xl font-bold tabular-nums text-content">{{ number_format($this->totalAppointmentDebt, 0, '.', ' ') }} сум</div>
            <div class="mt-1 text-xs text-brass-ink/50">{{ $this->appointmentDebts->count() }} записей</div>
        </div>
        <div class="overflow-hidden rounded-2xl border border-info/20 bg-gradient-to-br from-info/10 to-info/[0.02] p-5 backdrop-blur-md">
            <div class="text-xs font-bold uppercase tracking-widest text-info/70">По продажам</div>
            <div class="mt-2 font-display text-3xl font-bold tabular-nums text-content">{{ number_format($this->totalOrderDebt, 0, '.', ' ') }} сум</div>
            <div class="mt-1 text-xs text-info/50">{{ $this->orderDebts->count() }} продаж</div>
        </div>
    </div>

    {{-- Pay modal --}}
    @if ($payingAppointmentId || $payingOrderId)
        @php
            $debtRecord = $payingAppointmentId
                ? $this->appointmentDebts->firstWhere('id', $payingAppointmentId)
                : $this->orderDebts->firstWhere('id', $payingOrderId);
            $maxPay = (int) ($debtRecord?->debt_amount ?? 0);
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data
             x-on:keydown.escape.window="$wire.cancelPay()">
            <div class="absolute inset-0 bg-surface/80" wire:click="cancelPay"></div>
            <div class="relative z-10 w-full max-w-sm overflow-hidden rounded-2xl border border-content/[0.12] bg-surface-raised shadow-[0_32px_64px_rgba(0,0,0,0.8)]">
                <div class="flex items-center justify-between border-b border-content/[0.06] px-6 py-4">
                    <h3 class="text-sm font-bold text-content">Погасить долг</h3>
                    <button type="button" wire:click="cancelPay"
                            class="flex h-8 w-8 items-center justify-center rounded-lg text-content/30 transition hover:bg-content/[0.06] hover:text-content">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>
                <div class="px-6 py-6">
                    <div class="mb-4 rounded-xl border border-danger/20 bg-danger/5 px-4 py-3">
                        <div class="text-xs text-content/50">Клиент</div>
                        <div class="mt-0.5 font-bold text-content">{{ $debtRecord?->client?->name ?? '—' }}</div>
                        <div class="mt-1 text-xs text-danger/70">Остаток долга: <span class="font-bold">{{ number_format($maxPay, 0, '.', ' ') }} сум</span></div>
                    </div>
                    <div class="mb-1.5">
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">Сумма оплаты</label>
                        <div class="relative">
                            <input type="number" wire:model="payAmount"
                                   placeholder="0" min="1" max="{{ $maxPay }}"
                                   class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] py-3 pl-4 pr-12 text-sm text-content outline-none transition focus:border-success/40 focus:ring-1 focus:ring-success/20">
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[10px] font-medium text-content/25">сум</span>
                        </div>
                        @error('payAmount') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <button type="button"
                            x-data
                            x-on:click="$wire.payAmount = {{ $maxPay }}"
                            class="mb-4 text-xs text-brass-ink/70 hover:text-brass-ink">
                        Погасить полностью ({{ number_format($maxPay, 0, '.', ' ') }} сум)
                    </button>
                    <div class="flex gap-3">
                        <button type="button" wire:click="cancelPay"
                                class="rounded-xl border border-content/[0.08] px-5 py-2.5 text-sm font-bold text-content/60 transition hover:bg-content/[0.06] hover:text-content">
                            Отмена
                        </button>
                        <button type="button"
                                wire:click="{{ $payingAppointmentId ? 'payAppointmentDebt' : 'payOrderDebt' }}"
                                class="flex-1 rounded-xl bg-success px-6 py-2.5 text-sm font-bold text-black transition-all hover:bg-success active:scale-[0.98]">
                            Принять оплату
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Appointment debts --}}
    @if ($this->appointmentDebts->isNotEmpty())
        <div class="mb-6">
            <h2 class="mb-3 text-sm font-bold uppercase tracking-wider text-brass-ink/70">Долги по записям</h2>
            <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content/30">
                                <th class="px-6 py-4">Дата / Время</th>
                                <th class="px-6 py-4">Клиент</th>
                                <th class="px-6 py-4">Мастер / Услуги</th>
                                <th class="px-6 py-4 text-right">Сумма / Долг</th>
                                <th class="px-6 py-4 text-right">Действие</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-content/[0.04]">
                            @foreach ($this->appointmentDebts as $appointment)
                                <tr class="transition-colors hover:bg-content/[0.02]">
                                    <td class="whitespace-nowrap px-6 py-4">
                                        <div class="font-bold text-content">{{ $appointment->starts_at->format('d.m.Y') }}</div>
                                        <div class="text-[10px] text-content/30">{{ $appointment->starts_at->format('H:i') }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-bold text-content">{{ $appointment->client?->name ?? '—' }}</div>
                                        <div class="text-xs text-brass-ink/60">{{ $appointment->client?->formattedPhone }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-content/60">{{ $appointment->barber?->name }}</div>
                                        <div class="mt-0.5 text-[10px] text-content/30">{{ $appointment->services->pluck('name')->join(', ') }}</div>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right">
                                        <div class="text-xs text-content/40">{{ $appointment->formattedPrice }}</div>
                                        <div class="mt-0.5 font-extrabold text-danger tabular-nums">{{ $appointment->formattedDebt }}</div>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right">
                                        <button type="button" wire:click="openPayAppointment({{ $appointment->id }})"
                                                class="inline-flex items-center gap-1.5 rounded-xl bg-success/10 px-3 py-2 text-xs font-bold text-success transition hover:bg-success hover:text-black">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                            Оплатить
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- Order debts --}}
    @if ($this->orderDebts->isNotEmpty())
        <div class="mb-6">
            <h2 class="mb-3 text-sm font-bold uppercase tracking-wider text-info/70">Долги по продажам</h2>
            <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content/30">
                                <th class="px-6 py-4">Дата / Время</th>
                                <th class="px-6 py-4">Клиент</th>
                                <th class="px-6 py-4">Товары</th>
                                <th class="px-6 py-4 text-right">Сумма / Долг</th>
                                <th class="px-6 py-4 text-right">Действие</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-content/[0.04]">
                            @foreach ($this->orderDebts as $order)
                                <tr class="transition-colors hover:bg-content/[0.02]">
                                    <td class="whitespace-nowrap px-6 py-4">
                                        <div class="font-bold text-content">{{ $order->created_at->format('d.m.Y') }}</div>
                                        <div class="text-[10px] text-content/30">{{ $order->created_at->format('H:i') }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-bold text-content">{{ $order->client?->name ?? '—' }}</div>
                                        <div class="text-xs text-brass-ink/60">{{ $order->client?->formattedPhone }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($order->items as $item)
                                                <span class="inline-flex items-center rounded-full bg-content/[0.06] px-2 py-0.5 text-xs text-content/60">
                                                    {{ $item->product?->name ?? '—' }}
                                                    <span class="ml-1 font-bold text-content/40">×{{ $item->quantity }}</span>
                                                </span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right">
                                        <div class="text-xs text-content/40">{{ $order->formattedTotal }}</div>
                                        <div class="mt-0.5 font-extrabold text-danger tabular-nums">{{ $order->formattedDebt }}</div>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-right">
                                        <button type="button" wire:click="openPayOrder({{ $order->id }})"
                                                class="inline-flex items-center gap-1.5 rounded-xl bg-success/10 px-3 py-2 text-xs font-bold text-success transition hover:bg-success hover:text-black">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                            Оплатить
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if ($this->appointmentDebts->isEmpty() && $this->orderDebts->isEmpty())
        <div class="flex flex-col items-center gap-4 rounded-2xl border border-content/[0.06] bg-content/[0.03] py-20 text-center">
            <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-success/10 text-success">
                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            </div>
            <div>
                <p class="text-lg font-bold text-content">Долгов нет</p>
                <p class="mt-1 text-sm text-content/30">Все клиенты оплатили свои счета</p>
            </div>
        </div>
    @endif
</div>
