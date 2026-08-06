<?php

use App\Models\Appointment;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    public string $payPaymentType = 'cash';

    /**
     * Маршрутный middleware на update-запросах Livewire не переигрывается,
     * поэтому денежный экран сторожит себя сам.
     */
    public function mount(): void
    {
        $this->abortIfBarber();
    }

    private function abortIfBarber(): void
    {
        abort_if(auth()->user()?->isBarber() ?? false, 403);
    }

    #[Computed]
    public function appointmentDebts(): Collection
    {
        return Appointment::query()
            ->with(['client', 'barber', 'services'])
            ->withSum('debtPayments as debt_paid_total', 'amount')
            ->withOutstandingDebt()
            ->orderByDesc('starts_at')
            ->get();
    }

    #[Computed]
    public function orderDebts(): Collection
    {
        return Order::query()
            ->with(['client', 'items.product'])
            ->withSum('debtPayments as debt_paid_total', 'amount')
            ->withOutstandingDebt()
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function totalAppointmentDebt(): int
    {
        return (int) $this->appointmentDebts->sum(fn (Appointment $a) => $a->outstandingDebt);
    }

    #[Computed]
    public function totalOrderDebt(): int
    {
        return (int) $this->orderDebts->sum(fn (Order $o) => $o->outstandingDebt);
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
        $this->payAmount = Appointment::find($id)?->outstandingDebt;
        $this->payPaymentType = 'cash';
    }

    public function openPayOrder(int $id): void
    {
        $this->payingOrderId = $id;
        $this->payingAppointmentId = null;
        $this->payAmount = Order::find($id)?->outstandingDebt;
        $this->payPaymentType = 'cash';
    }

    public function cancelPay(): void
    {
        $this->payingAppointmentId = null;
        $this->payingOrderId = null;
        $this->payAmount = null;
        $this->payPaymentType = 'cash';
        $this->resetErrorBag('payAmount');
    }

    public function payAppointmentDebt(): void
    {
        $this->recordPayment(Appointment::findOrFail($this->payingAppointmentId));
        unset($this->appointmentDebts);
    }

    public function payOrderDebt(): void
    {
        $this->recordPayment(Order::findOrFail($this->payingOrderId));
        unset($this->orderDebts);
    }

    /**
     * Записываем погашение отдельной операцией с датой платежа: касса прошлых
     * дней не должна меняться задним числом, а деньги должны попасть в кассу
     * того дня, когда их реально приняли.
     */
    private function recordPayment(Appointment|Order $payable): void
    {
        $this->abortIfBarber();

        $method = in_array($this->payPaymentType, ['cash', 'card'], true)
            ? $this->payPaymentType
            : 'cash';
        $requested = (int) ($this->payAmount ?? 0);

        // Остаток перечитываем под блокировкой внутри транзакции: иначе два
        // параллельных запроса (двойной клик, вторая вкладка) читают один и тот
        // же остаток и записывают платёж дважды, завышая кассу дня.
        $recorded = DB::transaction(function () use ($payable, $requested, $method): int {
            $fresh = $payable->newQuery()
                ->lockForUpdate()
                ->find($payable->getKey());

            if ($fresh === null) {
                return 0;
            }

            $pay = min($requested, $fresh->outstandingDebt);

            if ($pay <= 0) {
                return 0;
            }

            $fresh->debtPayments()->create([
                'amount' => $pay,
                'payment_type' => $method,
                'paid_at' => Carbon::now('Asia/Tashkent'),
                'user_id' => auth()->id(),
            ]);

            return $pay;
        });

        if ($recorded <= 0) {
            $this->addError('payAmount', __('debts.err_enter_amount'));

            return;
        }

        $this->cancelPay();
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('debts.title') }}</h1>
            <p class="mt-1 text-sm text-content/40">{{ __('debts.subtitle') }}</p>
        </div>
    </div>

    {{-- Summary cards --}}
    <div class="mb-8 grid gap-4 sm:grid-cols-3">
        <div class="overflow-hidden rounded-2xl border border-danger/20 bg-gradient-to-br from-danger/10 to-danger/[0.02] p-5 backdrop-blur-md">
            <div class="text-xs font-bold uppercase tracking-widest text-danger/70">{{ __('debts.total_debts') }}</div>
            <div class="mt-2 font-display text-3xl font-bold tabular-nums text-content">{{ number_format($this->grandTotal, 0, '.', ' ') }} {{ __('common.currency') }}</div>
            <div class="mt-1 text-xs text-danger/50">{{ __('debts.positions', ['count' => $this->appointmentDebts->count() + $this->orderDebts->count()]) }}</div>
        </div>
        <div class="overflow-hidden rounded-2xl border border-brass/20 bg-gradient-to-br from-brass/10 to-brass/[0.02] p-5 backdrop-blur-md">
            <div class="text-xs font-bold uppercase tracking-widest text-brass-ink/70">{{ __('debts.by_appointments') }}</div>
            <div class="mt-2 font-display text-3xl font-bold tabular-nums text-content">{{ number_format($this->totalAppointmentDebt, 0, '.', ' ') }} {{ __('common.currency') }}</div>
            <div class="mt-1 text-xs text-brass-ink/50">{{ __('debts.appointments_count', ['count' => $this->appointmentDebts->count()]) }}</div>
        </div>
        <div class="overflow-hidden rounded-2xl border border-info/20 bg-gradient-to-br from-info/10 to-info/[0.02] p-5 backdrop-blur-md">
            <div class="text-xs font-bold uppercase tracking-widest text-info/70">{{ __('debts.by_orders') }}</div>
            <div class="mt-2 font-display text-3xl font-bold tabular-nums text-content">{{ number_format($this->totalOrderDebt, 0, '.', ' ') }} {{ __('common.currency') }}</div>
            <div class="mt-1 text-xs text-info/50">{{ __('debts.sales_count', ['count' => $this->orderDebts->count()]) }}</div>
        </div>
    </div>

    {{-- Pay modal --}}
    @if ($payingAppointmentId || $payingOrderId)
        @php
            $debtRecord = $payingAppointmentId
                ? $this->appointmentDebts->firstWhere('id', $payingAppointmentId)
                : $this->orderDebts->firstWhere('id', $payingOrderId);
            $maxPay = (int) ($debtRecord?->outstandingDebt ?? 0);
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data
             x-on:keydown.escape.window="$wire.cancelPay()">
            <div class="absolute inset-0 bg-surface/80 backdrop-blur-sm" wire:click="cancelPay"></div>
            <div class="relative z-10 w-full max-w-sm overflow-hidden rounded-2xl border border-content/[0.12] bg-surface-raised shadow-[0_32px_64px_rgba(0,0,0,0.8)]">
                <div class="flex items-center justify-between border-b border-content/[0.06] px-6 py-4">
                    <h3 class="text-sm font-bold text-content">{{ __('debts.pay_debt') }}</h3>
                    <button type="button" wire:click="cancelPay"
                            class="flex h-8 w-8 items-center justify-center rounded-lg text-content/30 transition hover:bg-content/[0.06] hover:text-content">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>
                <div class="px-6 py-6">
                    <div class="mb-4 rounded-xl border border-danger/20 bg-danger/5 px-4 py-3">
                        <div class="text-xs text-content/50">{{ __('common.client') }}</div>
                        <div class="mt-0.5 font-bold text-content">{{ $debtRecord?->client?->name ?? '—' }}</div>
                        <div class="mt-1 text-xs text-danger/70">{{ __('debts.remaining_debt') }}: <span class="font-bold">{{ number_format($maxPay, 0, '.', ' ') }} {{ __('common.currency') }}</span></div>
                    </div>
                    <div class="mb-1.5">
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('debts.pay_amount') }}</label>
                        <div class="relative">
                            <input type="number" wire:model="payAmount"
                                   placeholder="0" min="1" max="{{ $maxPay }}"
                                   class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] py-3 pl-4 pr-12 text-sm text-content outline-none transition focus:border-success/40 focus:ring-1 focus:ring-success/20">
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[10px] font-medium text-content/25">{{ __('common.currency') }}</span>
                        </div>
                        @error('payAmount') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <button type="button"
                            x-data
                            x-on:click="$wire.payAmount = {{ $maxPay }}"
                            class="mb-4 text-xs text-brass-ink/70 hover:text-brass-ink">
                        {{ __('debts.pay_full', ['amount' => number_format($maxPay, 0, '.', ' ').' '.__('common.currency')]) }}
                    </button>

                    {{-- Способ оплаты: платёж идёт в кассу дня платежа именно этим способом --}}
                    <div class="mb-4">
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('debts.pay_method') }}</label>
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" wire:click="$set('payPaymentType', 'cash')"
                                    @class([
                                        'rounded-xl border px-4 py-2.5 text-xs font-bold transition',
                                        'border-success/40 bg-success/10 text-success' => $payPaymentType === 'cash',
                                        'border-content/[0.08] text-content/50 hover:bg-content/[0.06]' => $payPaymentType !== 'cash',
                                    ])>
                                {{ __('enums.payment_type.cash') }}
                            </button>
                            <button type="button" wire:click="$set('payPaymentType', 'card')"
                                    @class([
                                        'rounded-xl border px-4 py-2.5 text-xs font-bold transition',
                                        'border-info/40 bg-info/10 text-info' => $payPaymentType === 'card',
                                        'border-content/[0.08] text-content/50 hover:bg-content/[0.06]' => $payPaymentType !== 'card',
                                    ])>
                                {{ __('enums.payment_type.card') }}
                            </button>
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <button type="button" wire:click="cancelPay"
                                class="rounded-xl border border-content/[0.08] px-5 py-2.5 text-sm font-bold text-content/60 transition hover:bg-content/[0.06] hover:text-content">
                            {{ __('common.cancel') }}
                        </button>
                        @php($payAction = $payingAppointmentId ? 'payAppointmentDebt' : 'payOrderDebt')
                        <button type="button"
                                wire:click="{{ $payAction }}"
                                wire:loading.attr="disabled"
                                wire:target="{{ $payAction }}"
                                class="flex-1 rounded-xl bg-success px-6 py-2.5 text-sm font-bold text-black transition-all hover:brightness-110 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60">
                            {{ __('debts.accept_payment') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Appointment debts --}}
    @if ($this->appointmentDebts->isNotEmpty())
        <div class="mb-6">
            <h2 class="mb-3 text-sm font-bold uppercase tracking-wider text-brass-ink/70">{{ __('debts.appointment_debts_title') }}</h2>
            <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content/30">
                                <th class="px-6 py-4">{{ __('debts.date_time') }}</th>
                                <th class="px-6 py-4">{{ __('common.client') }}</th>
                                <th class="px-6 py-4">{{ __('debts.barber_services') }}</th>
                                <th class="px-6 py-4 text-right">{{ __('debts.amount_debt') }}</th>
                                <th class="px-6 py-4 text-right">{{ __('debts.action') }}</th>
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
                                            {{ __('debts.pay') }}
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
            <h2 class="mb-3 text-sm font-bold uppercase tracking-wider text-info/70">{{ __('debts.order_debts_title') }}</h2>
            <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content/30">
                                <th class="px-6 py-4">{{ __('debts.date_time') }}</th>
                                <th class="px-6 py-4">{{ __('common.client') }}</th>
                                <th class="px-6 py-4">{{ __('nav.products') }}</th>
                                <th class="px-6 py-4 text-right">{{ __('debts.amount_debt') }}</th>
                                <th class="px-6 py-4 text-right">{{ __('debts.action') }}</th>
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
                                            {{ __('debts.pay') }}
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
                <p class="text-lg font-bold text-content">{{ __('debts.no_debts') }}</p>
                <p class="mt-1 text-sm text-content/30">{{ __('debts.all_paid') }}</p>
            </div>
        </div>
    @endif
</div>
