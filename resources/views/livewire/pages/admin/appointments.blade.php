<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    public string $date = '';

    public ?int $barberFilter = null;

    public string $sortField = 'starts_at';

    public string $sortDirection = 'asc';

    public ?int $editingId = null;

    #[Validate('required|exists:clients,id')]
    public ?int $client_id = null;

    #[Validate('required|exists:barbers,id')]
    public ?int $barber_id = null;

    /** @var array<int, array{service_id: int|null, amount: int|null}> */
    public array $selectedServices = [];

    #[Validate('nullable|integer|min:0')]
    public ?int $price = null;

    #[Validate('nullable|string|max:500')]
    public string $note = '';

    #[Validate('required|date')]
    public string $form_date = '';

    #[Validate('required|regex:/^\d{2}:\d{2}$/')]
    public string $form_start_time = '';

    #[Validate('required|regex:/^\d{2}:\d{2}$/')]
    public string $form_end_time = '';

    public bool $showForm = false;

    public int $timeStep = 60;

    public string $clientSearch = '';

    public function mount(): void
    {
        $this->date = Carbon::now()->toDateString();
        $this->form_date = $this->date;
    }

    #[Computed]
    public function filteredClients()
    {
        return Client::query()
            ->when($this->clientSearch, function ($q) {
                $q->where(function ($q) {
                    $q->where('name', 'like', "%{$this->clientSearch}%")
                    ->orWhere('phone', 'like', "%{$this->clientSearch}%");
                });
            })
            ->limit(20)
            ->get();
    }

    public function selectClient($id, $label)
    {
        $this->client_id = $id;
        $this->clientSearch = $label;
    }

    #[Computed]
    public function appointments()
    {
        return Appointment::query()
            ->with(['client', 'barber', 'services'])
            ->whereDate('starts_at', $this->date)
            ->when($this->barberFilter, fn ($q) => $q->where('barber_id', $this->barberFilter))
            ->orderBy($this->sortField, $this->sortDirection)
            ->get();
    }

    #[Computed]
    public function clients()
    {
        return Client::orderBy('name')->get();
    }

    #[Computed]
    public function barbers()
    {
        return Barber::active()->orderBy('name')->get();
    }

    #[Computed]
    public function services()
    {
        return Service::active()->orderBy('name')->get();
    }

    #[Computed]
    public function timeSlots(): array
    {
        $slots = [];
        $start = Carbon::createFromTime(0, 0);
        $end = Carbon::createFromTime(23, 0);

        while ($start <= $end) {
            $slots[] = $start->format('H:i');
            $start->addMinutes($this->timeStep);
        }

        return $slots;
    }

    public function setTimeStep(int $step): void
    {
        $this->timeStep = in_array($step, [15, 30, 60]) ? $step : 60;
        unset($this->timeSlots);
    }

    public function setDate(string $date): void
    {
        $this->date = $date;
        $this->form_date = $date;
    }

    public function nextDay(): void
    {
        $this->date = Carbon::parse($this->date)->addDay()->toDateString();
        $this->form_date = $this->date;
    }

    public function prevDay(): void
    {
        $this->date = Carbon::parse($this->date)->subDay()->toDateString();
        $this->form_date = $this->date;
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $appointment = Appointment::with('services')->findOrFail($id);
        $this->editingId = $appointment->id;
        $this->client_id = $appointment->client_id;
        $this->barber_id = $appointment->barber_id;
        $this->note = (string) $appointment->note;
        $this->form_date = $appointment->starts_at->toDateString();
        $this->form_start_time = $appointment->starts_at->format('H:i');
        $this->form_end_time = $appointment->ends_at->format('H:i');

        $this->selectedServices = $appointment->services->map(fn ($s) => [
            'service_id' => $s->id,
            'amount' => $s->pivot->amount,
        ])->values()->toArray();

        $this->recalculateTotal();
        $this->showForm = true;
    }

    public function addService(): void
    {
        $this->selectedServices[] = ['service_id' => null, 'amount' => null];
    }

    public function removeService(int $index): void
    {
        array_splice($this->selectedServices, $index, 1);
        $this->selectedServices = array_values($this->selectedServices);
        $this->recalculateTotal();
    }

    public function updatedSelectedServices($value, $key): void
    {
        if (str_ends_with((string) $key, 'service_id')) {
            $index = (int) explode('.', (string) $key)[0];
            $this->fillServiceAmount($index);
        }

        $this->recalculateTotal();
        $this->resetErrorBag('selectedServices');
    }

    private function fillServiceAmount(int $index): void
    {
        $row = $this->selectedServices[$index] ?? null;

        if (! $row || empty($row['service_id'])) {
            $this->selectedServices[$index]['amount'] = null;

            return;
        }

        if (! $this->barber_id) {
            return;
        }

        $barber = Barber::with('services')->find($this->barber_id);

        if ($barber) {
            $this->selectedServices[$index]['amount'] = $barber->priceForService((int) $row['service_id']) ?? 0;
        }
    }

    public function updatedBarberId($id): void
    {
        if (! $id) {
            return;
        }

        foreach (array_keys($this->selectedServices) as $i) {
            $this->fillServiceAmount($i);
        }

        $this->recalculateTotal();
    }

    private function recalculateTotal(): void
    {
        $this->price = collect($this->selectedServices)
            ->sum(fn ($row) => (int) ($row['amount'] ?? 0));
    }

    public function updatedFormStartTime($value): void
    {
        if (! preg_match('/^\d{2}:\d{2}$/', $value)) {
            return;
        }

        $firstServiceId = $this->selectedServices[0]['service_id'] ?? null;

        if (! $firstServiceId) {
            return;
        }

        $service = Service::find($firstServiceId);

        if ($service) {
            $this->form_end_time = Carbon::createFromFormat('H:i', $value)
                ->addMinutes((int) $service->duration_minutes)
                ->format('H:i');
        }

        $this->resetErrorBag('form_end_time');
    }

    public function updatedFormEndTime($value): void
    {
        if (
            ! preg_match('/^\d{2}:\d{2}$/', $value) ||
            ! preg_match('/^\d{2}:\d{2}$/', $this->form_start_time)
        ) {
            return;
        }

        $startsAt = Carbon::createFromFormat('H:i', $this->form_start_time);
        $endsAt = Carbon::createFromFormat('H:i', $value);

        if ($endsAt->lte($startsAt)) {
            $this->addError('form_end_time', 'Время окончания должно быть позже начала.');
        } else {
            $this->resetErrorBag('form_end_time');
        }
    }

    public function save(): void
    {
        $this->validate();

        $validServices = collect($this->selectedServices)
            ->filter(fn ($row) => ! empty($row['service_id']))
            ->values();

        if ($validServices->isEmpty()) {
            $this->addError('selectedServices', 'Добавьте хотя бы одну услугу.');

            return;
        }

        $uniqueIds = $validServices->pluck('service_id')->map(fn ($id) => (int) $id)->unique();

        if ($uniqueIds->count() !== $validServices->count()) {
            $this->addError('selectedServices', 'Каждая услуга должна быть указана только один раз.');

            return;
        }

        $startsAt = Carbon::parse($this->form_date.' '.$this->form_start_time);
        $endsAt = Carbon::parse($this->form_date.' '.$this->form_end_time);

        if ($endsAt->lte($startsAt)) {
            $this->addError('form_end_time', 'Время окончания должно быть позже времени начала.');

            return;
        }

        $this->recalculateTotal();

        $payload = [
            'client_id' => $this->client_id,
            'barber_id' => $this->barber_id,
            'price' => $this->price,
            'note' => $this->note ?: null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $this->editingId ? Appointment::find($this->editingId)->status : AppointmentStatus::Confirmed,
        ];

        if ($this->editingId) {
            $appointment = Appointment::findOrFail($this->editingId);
            $appointment->update($payload);
        } else {
            $appointment = Appointment::create($payload);
        }

        $syncData = $validServices->mapWithKeys(fn ($row) => [
            (int) $row['service_id'] => ['amount' => (int) ($row['amount'] ?? 0)],
        ])->toArray();

        $appointment->services()->sync($syncData);

        unset($this->appointments);
        $this->showForm = false;
        $this->resetForm();
    }

    public function markConfirmed(int $id): void
    {
        Appointment::findOrFail($id)->update(['status' => AppointmentStatus::Confirmed]);
        unset($this->appointments);
    }

    public function markCompleted(int $id): void
    {
        Appointment::findOrFail($id)->update(['status' => AppointmentStatus::Completed]);
        unset($this->appointments);
    }

    public function markCancelled(int $id): void
    {
        Appointment::findOrFail($id)->update(['status' => AppointmentStatus::Cancelled]);
        unset($this->appointments);
    }

    public function delete(int $id): void
    {
        Appointment::findOrFail($id)->delete();
        unset($this->appointments);
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'client_id', 'barber_id', 'selectedServices', 'price', 'note', 'form_start_time', 'form_end_time']);
        $this->form_date = $this->date;
        $this->resetErrorBag();
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-extrabold tracking-tight text-white">Записи</h1>
            <p class="mt-1 text-sm text-white/40">Управление журналом посещений</p>
        </div>
        <div class="flex items-center gap-3">
            <select wire:model.live="barberFilter"
                    class="rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-2.5 text-sm text-white outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20 [&>option]:bg-[#121212]">
                <option value="">Все мастера</option>
                @foreach ($this->barbers as $barber)
                    <option value="{{ $barber->id }}">{{ $barber->name }}</option>
                @endforeach
            </select>
            <button type="button" wire:click="openCreate"
                    class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-amber-400 to-amber-600 px-5 py-2.5 text-sm font-bold text-black shadow-lg shadow-amber-500/20 transition-all hover:scale-[1.02] hover:shadow-amber-500/30 active:scale-[0.98]">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Новая запись
            </button>
        </div>
    </div>

    {{-- Date Selector --}}
    <div class="mb-8 flex items-center justify-between gap-4 rounded-2xl border border-white/[0.06] bg-white/[0.03] p-4 backdrop-blur-md">
        <button wire:click="prevDay" class="flex h-10 w-10 items-center justify-center rounded-xl border border-white/[0.06] text-white/40 transition hover:border-white/10 hover:text-white">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
        </button>

        <div class="flex flex-col items-center">
            <span class="text-xs font-bold uppercase tracking-widest text-amber-500/60">{{ Carbon::parse($date)->translatedFormat('l') }}</span>
            <span class="text-lg font-extrabold text-white">{{ Carbon::parse($date)->translatedFormat('d F Y') }}</span>
        </div>

        <button wire:click="nextDay" class="flex h-10 w-10 items-center justify-center rounded-xl border border-white/[0.06] text-white/40 transition hover:border-white/10 hover:text-white">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
        </button>
    </div>

    @if ($showForm)
        <div class="mb-8 overflow-hidden rounded-2xl border border-white/[0.06] bg-white/[0.03] shadow-xl backdrop-blur-md">
            <div class="border-b border-white/[0.06] bg-white/[0.03] px-6 py-4">
                <h3 class="text-sm font-bold text-white">{{ $editingId ? 'Редактирование записи' : 'Создание новой записи' }}</h3>
            </div>
            <form wire:submit="save" class="p-6">
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-white/50">Клиент</label>
                        <x-search-select
                            :options="$this->filteredClients"
                            searchModel="clientSearch"
                            onSelect="selectClient"
                            labelField="name"
                            subLabelField="phone"
                            placeholder="Поиск клиента..."
                        />
                        @error('client_id') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-white/50">Мастер</label>
                        <select wire:model.live="barber_id"
                                class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20 [&>option]:bg-[#121212]">
                            <option value="">Выберите мастера...</option>
                            @foreach ($this->barbers as $barber)
                                <option value="{{ $barber->id }}">{{ $barber->name }} @if($barber->price)({{ $barber->formattedPrice }})@endif</option>
                            @endforeach
                        </select>
                        @error('barber_id') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-white/50">Дата</label>
                        <input type="date" wire:model="form_date"
                               class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20">
                        @error('form_date') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                    </div>

                    {{-- Services --}}
                    <div class="sm:col-span-2 lg:col-span-3">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <label class="text-xs font-semibold text-white/50">Услуги</label>
                            <button type="button" wire:click="addService"
                                    class="flex items-center gap-1.5 rounded-lg border border-white/[0.08] bg-white/[0.02] px-3 py-1.5 text-xs font-bold text-white/60 transition hover:border-amber-500/40 hover:bg-amber-500/5 hover:text-amber-400">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                Добавить услугу
                            </button>
                        </div>

                        @error('selectedServices') <p class="mb-3 text-xs text-rose-400">{{ $message }}</p> @enderror

                        @php
                            $usedServiceIds = collect($selectedServices)
                                ->pluck('service_id')
                                ->filter()
                                ->map(fn ($id) => (int) $id)
                                ->values()
                                ->toArray();
                        @endphp

                        <div class="overflow-hidden rounded-xl border border-white/[0.06]">
                            @forelse ($selectedServices as $i => $row)
                                @php
                                    $otherUsed = collect($usedServiceIds)
                                        ->filter(fn ($id) => $id !== (int) ($row['service_id'] ?? 0))
                                        ->values()
                                        ->toArray();
                                    $isDuplicate = isset($row['service_id']) && $row['service_id'] !== null &&
                                        collect($usedServiceIds)->filter(fn ($id) => $id === (int) $row['service_id'])->count() > 1;
                                @endphp
                                <div @class([
                                    'flex items-center gap-3 px-4 py-3',
                                    'border-b border-white/[0.04]' => ! $loop->last,
                                    'bg-rose-500/5' => $isDuplicate,
                                    'bg-white/[0.02]' => ! $isDuplicate,
                                ])>
                                    <span class="w-5 shrink-0 text-center text-xs font-bold text-white/20">{{ $i + 1 }}</span>
                                    <select wire:model.live="selectedServices.{{ $i }}.service_id"
                                            @class([
                                                'min-w-0 flex-1 rounded-lg border bg-transparent px-3 py-2 text-sm text-white outline-none transition [&>option]:bg-[#121212]',
                                                'border-rose-500/40 focus:border-rose-500/60' => $isDuplicate,
                                                'border-white/[0.08] focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20' => ! $isDuplicate,
                                            ])>
                                        <option value="">Выберите услугу...</option>
                                        @foreach ($this->services as $service)
                                            <option value="{{ $service->id }}"
                                                    @disabled(in_array($service->id, $otherUsed))>
                                                {{ $service->name }} ({{ $service->duration_minutes }} мин)
                                            </option>
                                        @endforeach
                                    </select>
                                    @if ($isDuplicate)
                                        <span class="shrink-0 text-[10px] font-bold text-rose-400">дубль</span>
                                    @endif
                                    <div class="relative w-36 shrink-0">
                                        <input type="number" wire:model.live="selectedServices.{{ $i }}.amount"
                                               placeholder="0"
                                               min="0"
                                               class="block w-full rounded-lg border border-white/[0.08] bg-transparent py-2 pl-3 pr-10 text-sm text-white outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20">
                                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[10px] font-medium text-white/25">сум</span>
                                    </div>
                                    <button type="button" wire:click="removeService({{ $i }})"
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-white/20 transition hover:bg-rose-500/10 hover:text-rose-400">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                            @empty
                                <div class="flex flex-col items-center gap-2 py-8 text-center">
                                    <svg class="h-8 w-8 text-white/10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" /></svg>
                                    <p class="text-xs text-white/25">Нет услуг — нажмите «Добавить услугу»</p>
                                </div>
                            @endforelse
                        </div>

                        @if (count($selectedServices) > 0)
                            <div class="mt-2.5 flex items-center justify-end gap-2 rounded-xl border border-amber-500/10 bg-amber-500/5 px-4 py-2.5">
                                <span class="text-xs text-white/40">Итого:</span>
                                <span class="text-sm font-bold text-amber-400">{{ number_format((int) $price, 0, '.', ' ') }} сум</span>
                            </div>
                        @endif
                    </div>

                    {{-- Time selects --}}
                    <div class="sm:col-span-2 lg:col-span-3">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <label class="text-xs font-semibold text-white/50">Время</label>
                            <div class="flex items-center gap-1 rounded-xl border border-white/[0.06] bg-white/[0.03] p-1">
                                @foreach ([15 => '15 мин', 30 => '30 мин', 60 => '1 ч'] as $step => $label)
                                    <button type="button" wire:click="setTimeStep({{ $step }})"
                                            @class([
                                                'rounded-lg px-3 py-1 text-xs font-bold transition',
                                                'bg-amber-500 text-black' => $timeStep === $step,
                                                'text-white/40 hover:text-white' => $timeStep !== $step,
                                            ])>
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-white/40">Начало</label>
                                <select wire:model.live="form_start_time"
                                        class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20 [&>option]:bg-[#121212]">
                                    <option value="">— выберите —</option>
                                    @foreach ($this->timeSlots as $slot)
                                        <option value="{{ $slot }}">{{ $slot }}</option>
                                    @endforeach
                                </select>
                                @error('form_start_time') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-white/40">Окончание</label>
                                <select wire:model.live="form_end_time"
                                        class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20 [&>option]:bg-[#121212]">
                                    <option value="">— выберите —</option>
                                    @foreach ($this->timeSlots as $slot)
                                        <option value="{{ $slot }}">{{ $slot }}</option>
                                    @endforeach
                                </select>
                                @error('form_end_time') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>

                    <div class="sm:col-span-2 lg:col-span-3">
                        <label class="mb-1.5 block text-xs font-semibold text-white/50">Заметка</label>
                        <textarea wire:model="note" rows="2" placeholder="Дополнительная информация..."
                                  class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white placeholder-white/20 outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20"></textarea>
                        @error('note') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-8 flex items-center justify-end gap-3 border-t border-white/[0.06] pt-6">
                    <button type="button" wire:click="cancel"
                            class="rounded-xl border border-white/[0.08] px-5 py-2.5 text-sm font-bold text-white/60 transition hover:bg-white/[0.06] hover:text-white">
                        Отмена
                    </button>
                    <button type="submit"
                            class="rounded-xl bg-amber-500 px-6 py-2.5 text-sm font-bold text-black transition-all hover:bg-amber-400 active:scale-[0.98]">
                        {{ $editingId ? 'Сохранить изменения' : 'Создать запись' }}
                    </button>
                </div>
            </form>
        </div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-white/[0.06] bg-white/[0.03] shadow-xl backdrop-blur-md">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-white/[0.06] bg-white/[0.03] text-xs font-bold uppercase tracking-wider text-white/30">
                        <th class="cursor-pointer px-6 py-4 transition hover:text-white" wire:click="sortBy('starts_at')">
                            Время
                            @if ($sortField === 'starts_at')
                                <span class="ml-1">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                        <th class="px-6 py-4">Клиент / Заметка</th>
                        <th class="hidden px-6 py-4 md:table-cell">Мастер</th>
                        <th class="hidden px-6 py-4 md:table-cell">Услуги / Итого</th>
                        <th class="cursor-pointer px-6 py-4 transition hover:text-white" wire:click="sortBy('status')">
                            Статус
                            @if ($sortField === 'status')
                                <span class="ml-1">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                        <th class="px-6 py-4 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/[0.04]">
                    @forelse ($this->appointments as $appointment)
                        <tr class="transition-colors hover:bg-white/[0.02]">
                            <td class="whitespace-nowrap px-6 py-4">
                                <div class="font-bold text-white">{{ $appointment->starts_at->format('H:i') }}</div>
                                <div class="text-[10px] text-white/30">{{ $appointment->ends_at->format('H:i') }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-bold text-white">{{ $appointment->client?->name }}</div>
                                <div class="text-xs text-amber-500/60">{{ $appointment->client?->formattedPhone }}</div>
                                @if ($appointment->note)
                                    <div class="mt-1 flex items-start gap-1.5 text-[11px] text-white/40">
                                        <svg class="mt-0.5 h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" /></svg>
                                        <span class="italic">{{ $appointment->note }}</span>
                                    </div>
                                @endif
                                <div class="mt-1 text-[10px] text-white/30 md:hidden">
                                    {{ $appointment->barber?->name }} · {{ $appointment->services->pluck('name')->join(', ') }}
                                </div>
                            </td>
                            <td class="hidden px-6 py-4 md:table-cell">
                                <div class="flex items-center gap-2">
                                    @if ($appointment->barber?->photoUrl)
                                        <img src="{{ $appointment->barber->photoUrl }}" class="h-6 w-6 rounded-full object-cover">
                                    @endif
                                    <span class="font-medium text-white/60">{{ $appointment->barber?->name }}</span>
                                </div>
                            </td>
                            <td class="hidden px-6 py-4 md:table-cell">
                                <div class="space-y-0.5">
                                    @foreach ($appointment->services as $service)
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="text-white/60">{{ $service->name }}</span>
                                            <span class="text-[10px] font-bold text-white/40">{{ number_format($service->pivot->amount, 0, '.', ' ') }} сум</span>
                                        </div>
                                    @endforeach
                                </div>
                                @if ($appointment->services->isNotEmpty())
                                    <div class="mt-1 border-t border-white/[0.04] pt-1">
                                        <span @class([
                                            'text-[10px] font-bold',
                                            'text-amber-400' => $appointment->price !== null,
                                            'text-white/30' => $appointment->price === null,
                                        ])>
                                            Итого: {{ $appointment->formattedPrice }}
                                        </span>
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @php($badge = $appointment->status->badgeClasses())
                                @php($label = $appointment->status->label())
                                <span @class([
                                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider',
                                    'bg-emerald-500/10 text-emerald-400' => str_contains($badge, 'emerald'),
                                    'bg-amber-500/10 text-amber-400' => str_contains($badge, 'amber'),
                                    'bg-blue-500/10 text-blue-400' => str_contains($badge, 'blue'),
                                    'bg-rose-500/10 text-rose-400' => str_contains($badge, 'rose'),
                                    'bg-white/10 text-white/40' => str_contains($badge, 'zinc'),
                                ])>
                                    {{ $label }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($appointment->status === AppointmentStatus::Pending)
                                        <button type="button" wire:click="markConfirmed({{ $appointment->id }})"
                                                title="Подтвердить"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-500/10 text-blue-400 transition hover:bg-blue-500 hover:text-black">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        </button>
                                        <button type="button" wire:click="markCancelled({{ $appointment->id }})"
                                                title="Отменить"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-500/10 text-rose-500 transition hover:bg-rose-500 hover:text-black">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                        </button>
                                    @elseif ($appointment->status === AppointmentStatus::Confirmed)
                                        <button type="button" wire:click="markCompleted({{ $appointment->id }})"
                                                title="Завершить"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-500 transition hover:bg-emerald-500 hover:text-black">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        </button>
                                        <button type="button" wire:click="markCancelled({{ $appointment->id }})"
                                                title="Отменить"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-500/10 text-rose-500 transition hover:bg-rose-500 hover:text-black">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                        </button>
                                    @endif
                                    <button type="button" wire:click="edit({{ $appointment->id }})"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-white/[0.06] text-white/40 transition hover:border-white/10 hover:text-white">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" /></svg>
                                    </button>
                                    <button type="button" wire:click="delete({{ $appointment->id }})"
                                            wire:confirm="Удалить запись?"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-white/[0.06] text-white/20 transition hover:text-rose-500">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-white/20">На этот день записей нет</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
