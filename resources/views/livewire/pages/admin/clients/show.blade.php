<?php

use App\Enums\AppointmentStatus;
use App\Models\Barber;
use App\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    /** Сколько строк истории показываем за раз и потолок для «показать ещё». */
    private const HISTORY_PAGE = 20;

    private const HISTORY_MAX = 500;

    private const TABS = ['appointments', 'orders', 'sms'];

    public Client $client;

    #[Validate('nullable|string|max:5000')]
    public string $notes = '';

    /** Активная вкладка истории: панель рендерится и квepится только одна. */
    public string $tab = 'appointments';

    public int $historyLimit = self::HISTORY_PAGE;

    public bool $editing = false;

    public string $name = '';

    public string $phone = '';

    public string $birth_date = '';

    public function mount(Client $client): void
    {
        $this->client = $client;
        $this->notes = $client->notes ?? '';
        $this->fillProfileForm();
    }

    private function fillProfileForm(): void
    {
        $this->name = $this->client->name;
        $this->phone = $this->client->formattedPhone ?: $this->client->phone;
        $this->birth_date = $this->client->birth_date?->format('Y-m-d') ?? '';
    }

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'appointments';
        $this->historyLimit = self::HISTORY_PAGE;
    }

    public function showMoreHistory(): void
    {
        $this->historyLimit = $this->historyRows() + self::HISTORY_PAGE;

        unset($this->appointmentHistory, $this->orderHistory, $this->smsHistory);
    }

    /**
     * Сколько строк истории грузить. `$historyLimit` — публичное свойство, то
     * есть клиентское: без потолка крафченый запрос вытянул бы всю историю.
     */
    private function historyRows(): int
    {
        return max(self::HISTORY_PAGE, min($this->historyLimit, self::HISTORY_MAX));
    }

    #[Computed]
    public function hasMoreHistory(): bool
    {
        $total = match ($this->tab) {
            'orders' => $this->ordersCount,
            'sms' => $this->smsCount,
            default => $this->appointmentsCount,
        };

        return $total > $this->historyRows();
    }

    #[Computed]
    public function appointmentsCount(): int
    {
        return $this->client->appointments()->count();
    }

    #[Computed]
    public function ordersCount(): int
    {
        return $this->client->orders()->count();
    }

    #[Computed]
    public function smsCount(): int
    {
        return $this->client->smsMessages()->count();
    }

    public function edit(): void
    {
        $this->fillProfileForm();
        $this->resetErrorBag();
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->fillProfileForm();
        $this->resetErrorBag();
        $this->editing = false;
    }

    /**
     * Правка карточки прямо здесь: раньше за опечаткой в имени приходилось
     * возвращаться в список и искать клиента заново.
     */
    public function saveProfile(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string'],
            'birth_date' => ['nullable', 'date'],
        ]);

        $normalized = Client::normalizePhone($this->phone);

        if ($normalized === null) {
            $this->addError('phone', __('clients.err_phone_format'));

            return;
        }

        $duplicate = Client::query()
            ->where('phone', $normalized)
            ->whereKeyNot($this->client->id)
            ->exists();

        if ($duplicate) {
            $this->addError('phone', __('clients.err_duplicate'));

            return;
        }

        $this->client->update([
            'name' => $this->name,
            'phone' => $normalized,
            'birth_date' => $this->birth_date ?: null,
        ]);

        $this->fillProfileForm();
        $this->editing = false;
        $this->dispatch('saved', message: __('clients.profile_saved'));
    }

    #[Computed]
    public function visitsCount(): int
    {
        return $this->client->appointments()
            ->where('status', AppointmentStatus::Completed->value)
            ->count();
    }

    #[Computed]
    public function cancelledCount(): int
    {
        return $this->client->appointments()
            ->where('status', AppointmentStatus::Cancelled->value)
            ->count();
    }

    #[Computed]
    public function totalSpent(): int
    {
        $fromAppointments = (int) $this->client->appointments()
            ->where('status', AppointmentStatus::Completed->value)
            ->sum('price');

        $fromOrders = (int) $this->client->orders()->sum('total_price');

        return $fromAppointments + $fromOrders;
    }

    #[Computed]
    public function transactionsCount(): int
    {
        return $this->visitsCount + (int) $this->client->orders()->count();
    }

    #[Computed]
    public function averageCheck(): int
    {
        return $this->transactionsCount > 0
            ? intdiv($this->totalSpent, $this->transactionsCount)
            : 0;
    }

    /**
     * Непогашенный долг клиента: выданное минус то, что он уже принёс.
     */
    #[Computed]
    public function totalDebt(): int
    {
        $outstanding = function ($relation): int {
            return (int) $relation
                ->withSum('debtPayments as debt_paid_total', 'amount')
                ->withOutstandingDebt()
                ->get()
                ->sum(fn ($row) => $row->outstandingDebt);
        };

        return $outstanding($this->client->appointments())
            + $outstanding($this->client->orders());
    }

    #[Computed]
    public function favoriteBarber(): ?Barber
    {
        $row = $this->client->appointments()
            ->whereNotNull('barber_id')
            ->selectRaw('barber_id, COUNT(*) as cnt')
            ->groupBy('barber_id')
            ->orderByDesc('cnt')
            ->first();

        return $row ? Barber::find($row->barber_id) : null;
    }

    /**
     * Топ услуг клиента по частоте.
     *
     * @return Collection<int, object{name: string, cnt: int}>
     */
    #[Computed]
    public function topServices(): Collection
    {
        return DB::table('appointment_service')
            ->join('appointments', 'appointments.id', '=', 'appointment_service.appointment_id')
            ->join('services', 'services.id', '=', 'appointment_service.service_id')
            ->where('appointments.client_id', $this->client->id)
            ->selectRaw('services.name as name, COUNT(*) as cnt')
            ->groupBy('services.id', 'services.name')
            ->orderByDesc('cnt')
            ->limit(3)
            ->get();
    }

    /**
     * Истории режутся лимитом и живут за серверными вкладками: у лояльного
     * клиента их сотни, а раньше все три квepились и уезжали в HTML на каждый
     * рендер — даже скрытые.
     */
    #[Computed]
    public function appointmentHistory(): Collection
    {
        return $this->client->appointments()
            ->with(['barber', 'services'])
            ->orderByDesc('starts_at')
            ->limit($this->historyRows())
            ->get();
    }

    #[Computed]
    public function orderHistory(): Collection
    {
        return $this->client->orders()
            ->with('items.product')
            ->withSum('debtPayments as debt_paid_total', 'amount')
            ->orderByDesc('created_at')
            ->limit($this->historyRows())
            ->get();
    }

    #[Computed]
    public function smsHistory(): Collection
    {
        return $this->client->smsMessages()
            ->orderByDesc('created_at')
            ->limit($this->historyRows())
            ->get();
    }

    public function saveNotes(): void
    {
        $this->validate();

        $this->client->update(['notes' => $this->notes ?: null]);

        $this->dispatch('saved');
    }

    public function money(int $amount): string
    {
        return number_format($amount, 0, '.', ' ').' '.__('common.currency');
    }
}; ?>

<div class="animate-fade-in-up">
    <a href="{{ route('admin.clients') }}" wire:navigate
       class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-content/40 transition hover:text-content">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
        {{ __('clients.title') }}
    </a>

    {{-- Header --}}
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ $client->name }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-3 text-sm">
                <span class="font-medium text-brass-ink/60">{{ $client->formattedPhone }}</span>
                @if ($client->telegram_chat_id !== null)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-3 py-1 text-xs font-bold text-success">
                        <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>
                        Telegram
                    </span>
                @endif
            </div>
        </div>

        {{-- Самое частое действие после открытия карточки — записать на визит --}}
        <div class="flex items-center gap-3">
            <button type="button" wire:click="edit"
                    class="inline-flex items-center gap-2 rounded-xl border border-content/[0.08] px-4 py-2.5 text-sm font-bold text-content/60 transition hover:bg-content/[0.06] hover:text-content">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" /></svg>
                {{ __('common.edit') }}
            </button>
            <a href="{{ route('admin.appointments', ['client' => $client->id]) }}" wire:navigate
               class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-brass to-brass px-5 py-2.5 text-sm font-bold text-black shadow-lg shadow-brass/20 transition-all hover:scale-[1.02] hover:shadow-brass/30 active:scale-[0.98]">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ __('clients.book_visit') }}
            </a>
        </div>
    </div>

    {{-- Inline profile edit --}}
    @if ($editing)
        <div class="mb-6 overflow-hidden rounded-2xl border border-brass/20 bg-content/[0.03] shadow-xl backdrop-blur-md">
            <div class="border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
                <h3 class="text-sm font-bold text-content">{{ __('clients.edit_title') }}</h3>
            </div>
            <form wire:submit="saveProfile" class="p-6">
                <div class="grid gap-6 sm:grid-cols-3">
                    <div>
                        <label for="client-name" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('common.name') }}</label>
                        <input id="client-name" type="text" wire:model="name" placeholder="{{ __('clients.name_placeholder') }}"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('name') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="client-phone" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('common.phone') }}</label>
                        <input id="client-phone" type="text" wire:model="phone" placeholder="+998 90 123 45 67"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('phone') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="client-birth" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('common.birth_date') }}</label>
                        <input id="client-birth" type="date" wire:model="birth_date"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 dark:[color-scheme:dark]">
                        @error('birth_date') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-8 flex items-center justify-end gap-3 border-t border-content/[0.06] pt-6">
                    <button type="button" wire:click="cancelEdit"
                            class="rounded-xl border border-content/[0.08] px-5 py-2.5 text-sm font-bold text-content/60 transition hover:bg-content/[0.06] hover:text-content">
                        {{ __('common.cancel') }}
                    </button>
                    <x-submit-button target="saveProfile">{{ __('common.save_changes') }}</x-submit-button>
                </div>
            </form>
        </div>
    @endif

    {{-- Profile facts --}}
    <div class="mb-6 grid gap-4 rounded-2xl border border-content/[0.06] bg-content/[0.03] p-6 shadow-xl backdrop-blur-md sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-content/30">{{ __('common.birth_date') }}</p>
            <p class="mt-1 text-sm text-content/80">
                {{ $client->formattedBirthDate }}
                @if ($client->birth_date)
                    <span class="text-content/40">· {{ __('clients.age', ['age' => $client->birth_date->age]) }}</span>
                @endif
            </p>
        </div>
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-content/30">{{ __('clients.client_since') }}</p>
            <p class="mt-1 text-sm text-content/80">{{ $client->created_at ? \App\Models\Client::formatLocalizedDate($client->created_at) : '—' }}</p>
        </div>
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-content/30">{{ __('clients.last_visit') }}</p>
            <p class="mt-1 text-sm text-content/80">{{ $client->formattedLastVisit }}</p>
        </div>
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-content/30">{{ __('clients.telegram') }}</p>
            <p class="mt-1 text-sm text-content/80">{{ $client->telegram_chat_id !== null ? __('clients.linked') : __('clients.not_linked') }}</p>
        </div>
    </div>

    {{-- Metrics --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl border border-content/[0.06] bg-content/[0.03] p-6 shadow-xl backdrop-blur-md">
            <p class="text-xs font-semibold uppercase tracking-wider text-content/30">{{ __('clients.metric_visits') }}</p>
            <p class="mt-2 font-display text-3xl font-semibold text-content">{{ $this->visitsCount }}</p>
            <p class="mt-1 text-xs text-content/40">{{ __('clients.metric_cancelled', ['count' => $this->cancelledCount]) }}</p>
        </div>
        <div class="rounded-2xl border border-content/[0.06] bg-content/[0.03] p-6 shadow-xl backdrop-blur-md">
            <p class="text-xs font-semibold uppercase tracking-wider text-content/30">{{ __('clients.metric_spent') }}</p>
            <p class="mt-2 font-display text-3xl font-semibold text-content">{{ $this->money($this->totalSpent) }}</p>
            <p class="mt-1 text-xs text-content/40">{{ __('clients.metric_avg_check') }}: {{ $this->money($this->averageCheck) }}</p>
        </div>
        <div class="rounded-2xl border border-content/[0.06] bg-content/[0.03] p-6 shadow-xl backdrop-blur-md">
            <p class="text-xs font-semibold uppercase tracking-wider text-content/30">{{ __('clients.metric_debt') }}</p>
            <p class="mt-2 font-display text-3xl font-semibold {{ $this->totalDebt > 0 ? 'text-danger' : 'text-content' }}">{{ $this->money($this->totalDebt) }}</p>
        </div>
        <div class="rounded-2xl border border-content/[0.06] bg-content/[0.03] p-6 shadow-xl backdrop-blur-md">
            <p class="text-xs font-semibold uppercase tracking-wider text-content/30">{{ __('clients.metric_favorite_barber') }}</p>
            <p class="mt-2 truncate text-lg font-bold text-content">{{ $this->favoriteBarber?->name ?? '—' }}</p>
            @if ($this->topServices->isNotEmpty())
                <p class="mt-1 truncate text-xs text-content/40">{{ $this->topServices->pluck('name')->join(', ') }}</p>
            @endif
        </div>
    </div>

    {{-- Notes --}}
    <div class="mb-6 overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="flex items-center justify-between border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
            <h3 class="text-sm font-bold text-content">{{ __('clients.notes_title') }}</h3>
        </div>
        <form wire:submit="saveNotes" class="p-6">
            <textarea wire:model="notes" rows="3" placeholder="{{ __('clients.notes_placeholder') }}"
                      class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20"></textarea>
            @error('notes') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
            <div class="mt-4 flex items-center justify-end">
                <x-submit-button target="saveNotes">{{ __('common.save') }}</x-submit-button>
            </div>
        </form>
    </div>

    {{-- History tabs. Вкладки серверные: скрытая история не должна квepиться --}}
    <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="flex flex-wrap gap-1 border-b border-content/[0.06] bg-content/[0.03] p-2">
            @foreach ([
                'appointments' => __('clients.tab_appointments').' ('.$this->appointmentsCount.')',
                'orders' => __('clients.tab_orders').' ('.$this->ordersCount.')',
                'sms' => __('clients.tab_sms').' ('.$this->smsCount.')',
            ] as $key => $label)
                <button type="button" wire:click="showTab('{{ $key }}')" wire:key="tab-{{ $key }}"
                        @class([
                            'rounded-lg px-4 py-2 text-sm font-bold transition',
                            'bg-brass text-on-brass' => $tab === $key,
                            'text-content/50 hover:bg-content/[0.06] hover:text-content' => $tab !== $key,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Appointments --}}
        @if ($tab === 'appointments')
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-content/[0.06] text-xs font-bold uppercase tracking-wider text-content/30">
                        <th class="px-6 py-4">{{ __('common.date') }}</th>
                        <th class="px-6 py-4">{{ __('common.barber') }}</th>
                        <th class="px-6 py-4">{{ __('common.services') }}</th>
                        <th class="px-6 py-4">{{ __('common.price') }}</th>
                        <th class="px-6 py-4">{{ __('common.status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-content/[0.04]">
                    @forelse ($this->appointmentHistory as $appointment)
                        <tr class="transition-colors hover:bg-content/[0.02]">
                            <td class="whitespace-nowrap px-6 py-4 text-content/70">{{ $appointment->starts_at->format('d.m.Y H:i') }}</td>
                            <td class="px-6 py-4 text-content/70">{{ $appointment->barber?->name ?? '—' }}</td>
                            <td class="px-6 py-4 text-content/50">{{ $appointment->services->pluck('name')->join(', ') ?: '—' }}</td>
                            <td class="whitespace-nowrap px-6 py-4 font-medium text-content/70">{{ $appointment->formattedPrice }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ $appointment->status->badgeClasses() }}">{{ $appointment->status->label() }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-6 py-12 text-center text-content/20">{{ __('clients.empty_appointments') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @endif

        {{-- Orders --}}
        @if ($tab === 'orders')
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-content/[0.06] text-xs font-bold uppercase tracking-wider text-content/30">
                        <th class="px-6 py-4">{{ __('common.date') }}</th>
                        <th class="px-6 py-4">{{ __('clients.order_items') }}</th>
                        <th class="px-6 py-4">{{ __('common.total') }}</th>
                        <th class="px-6 py-4">{{ __('common.debt') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-content/[0.04]">
                    @forelse ($this->orderHistory as $order)
                        <tr class="transition-colors hover:bg-content/[0.02]">
                            <td class="whitespace-nowrap px-6 py-4 text-content/70">{{ $order->created_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td class="px-6 py-4 text-content/50">
                                @foreach ($order->items as $item)
                                    <div>{{ $item->product?->name ?? '—' }} × {{ $item->quantity }}</div>
                                @endforeach
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 font-medium text-content/70">{{ $order->formattedTotal }}</td>
                            <td class="whitespace-nowrap px-6 py-4 {{ $order->hasDebt ? 'text-danger' : 'text-content/40' }}">{{ $order->formattedDebt }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-12 text-center text-content/20">{{ __('clients.empty_orders') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @endif

        {{-- SMS --}}
        @if ($tab === 'sms')
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-content/[0.06] text-xs font-bold uppercase tracking-wider text-content/30">
                        <th class="px-6 py-4">{{ __('common.date') }}</th>
                        <th class="px-6 py-4">{{ __('sms.col_type') }}</th>
                        <th class="px-6 py-4">{{ __('sms.col_message') }}</th>
                        <th class="px-6 py-4">{{ __('common.status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-content/[0.04]">
                    @forelse ($this->smsHistory as $sms)
                        <tr class="transition-colors hover:bg-content/[0.02]">
                            <td class="whitespace-nowrap px-6 py-4 text-content/70">{{ $sms->created_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td class="px-6 py-4 text-content/50">{{ __('sms.context_'.$sms->context) }}</td>
                            <td class="px-6 py-4 text-content/50">{{ $sms->message }}</td>
                            <td class="px-6 py-4">
                                @if ($sms->isSent())
                                    <span class="inline-flex rounded-full bg-success/10 px-2.5 py-1 text-xs font-bold text-success">{{ __('sms.sent') }}</span>
                                @else
                                    <span class="inline-flex rounded-full bg-danger/10 px-2.5 py-1 text-xs font-bold text-danger">{{ __('sms.error') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-12 text-center text-content/20">{{ __('clients.empty_sms') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @endif

        @if ($this->hasMoreHistory)
            <div class="border-t border-content/[0.06] p-4 text-center">
                <button type="button" wire:click="showMoreHistory" wire:loading.attr="disabled" wire:target="showMoreHistory"
                        class="inline-flex items-center gap-2 rounded-xl border border-content/[0.08] bg-content/[0.04] px-5 py-2.5 text-sm font-bold text-content/60 transition hover:bg-content/[0.08] hover:text-content disabled:opacity-60">
                    <span wire:loading.remove wire:target="showMoreHistory">{{ __('clients.show_more') }}</span>
                    <span wire:loading wire:target="showMoreHistory">{{ __('common.loading') }}</span>
                </button>
            </div>
        @endif
    </div>
</div>
