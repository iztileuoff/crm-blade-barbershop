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

    #[Validate('required|exists:services,id')]
    public ?int $service_id = null;

    #[Validate('required|date')]
    public string $form_date = '';

    #[Validate('required|regex:/^\d{2}:\d{2}$/')]
    public string $form_start_time = '';

    #[Validate('required|regex:/^\d{2}:\d{2}$/')]
    public string $form_end_time = '';

    public bool $showForm = false;

    public function mount(): void
    {
        $this->date = Carbon::now()->toDateString();
        $this->form_date = $this->date;
    }

    #[Computed]
    public function appointments()
    {
        return Appointment::query()
            ->with(['client', 'barber', 'service'])
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
        $appointment = Appointment::findOrFail($id);
        $this->editingId = $appointment->id;
        $this->client_id = $appointment->client_id;
        $this->barber_id = $appointment->barber_id;
        $this->service_id = $appointment->service_id;
        $this->form_date = $appointment->starts_at->toDateString();
        $this->form_start_time = $appointment->starts_at->format('H:i');
        $this->form_end_time = $appointment->ends_at->format('H:i');
        $this->showForm = true;
    }

    public function updatedServiceId($id): void
    {
        if (! $this->form_start_time || ! $id) {
            return;
        }

        $service = Service::find($id);
        if ($service) {
            $this->form_end_time = Carbon::parse($this->form_start_time)
                ->addMinutes((int) $service->duration_minutes)
                ->format('H:i');
        }
    }

    public function save(): void
    {
        $this->validate();

        $startsAt = Carbon::parse($this->form_date.' '.$this->form_start_time);
        $endsAt = Carbon::parse($this->form_date.' '.$this->form_end_time);

        if ($endsAt->lte($startsAt)) {
            $this->addError('form_end_time', 'Время окончания должно быть позже времени начала.');

            return;
        }

        $payload = [
            'client_id' => $this->client_id,
            'barber_id' => $this->barber_id,
            'service_id' => $this->service_id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => AppointmentStatus::Confirmed,
        ];

        if ($this->editingId) {
            Appointment::findOrFail($this->editingId)->update($payload);
        } else {
            Appointment::create($payload);
        }

        unset($this->appointments);
        $this->showForm = false;
        $this->resetForm();
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
        $this->reset(['editingId', 'client_id', 'barber_id', 'service_id', 'form_start_time', 'form_end_time']);
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
                        <select wire:model="client_id"
                                class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20 [&>option]:bg-[#121212]">
                            <option value="">Выберите клиента...</option>
                            @foreach ($this->clients as $client)
                                <option value="{{ $client->id }}">{{ $client->name }} ({{ $client->phone }})</option>
                            @endforeach
                        </select>
                        @error('client_id') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-white/50">Мастер</label>
                        <select wire:model="barber_id"
                                class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20 [&>option]:bg-[#121212]">
                            <option value="">Выберите мастера...</option>
                            @foreach ($this->barbers as $barber)
                                <option value="{{ $barber->id }}">{{ $barber->name }}</option>
                            @endforeach
                        </select>
                        @error('barber_id') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-white/50">Услуга</label>
                        <select wire:model.live="service_id"
                                class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20 [&>option]:bg-[#121212]">
                            <option value="">Выберите услугу...</option>
                            @foreach ($this->services as $service)
                                <option value="{{ $service->id }}">{{ $service->name }} ({{ $service->formattedPrice }})</option>
                            @endforeach
                        </select>
                        @error('service_id') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-white/50">Дата</label>
                        <input type="date" wire:model="form_date"
                               class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20">
                        @error('form_date') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-white/50">Начало</label>
                        <input type="text" wire:model="form_start_time" placeholder="09:00"
                               class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20">
                        @error('form_start_time') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-white/50">Окончание</label>
                        <input type="text" wire:model="form_end_time" placeholder="09:45"
                               class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20">
                        @error('form_end_time') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
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
                        <th class="px-6 py-4">Клиент</th>
                        <th class="hidden px-6 py-4 md:table-cell">Мастер</th>
                        <th class="hidden px-6 py-4 md:table-cell">Услуга</th>
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
                                <div class="mt-1 text-[10px] text-white/30 md:hidden">
                                    {{ $appointment->barber?->name }} · {{ $appointment->service?->name }}
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
                                <div class="text-white/60">{{ $appointment->service?->name }}</div>
                                <div class="text-[10px] text-white/30">{{ $appointment->service?->formattedPrice }}</div>
                            </td>
                            <td class="px-6 py-4">
                                @php($badge = $appointment->status->badgeClasses())
                                @php($label = $appointment->status->label())
                                <span @class([
                                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider',
                                    'bg-emerald-500/10 text-emerald-400' => str_contains($badge, 'emerald'),
                                    'bg-amber-500/10 text-amber-400' => str_contains($badge, 'amber'),
                                    'bg-rose-500/10 text-rose-400' => str_contains($badge, 'rose'),
                                    'bg-white/10 text-white/40' => str_contains($badge, 'zinc'),
                                ])>
                                    {{ $label }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if (in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true))
                                        <button type="button" wire:click="markCompleted({{ $appointment->id }})"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-500 transition hover:bg-emerald-500 hover:text-black">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        </button>
                                        <button type="button" wire:click="markCancelled({{ $appointment->id }})"
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
