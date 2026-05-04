<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    public string $date = '';

    public ?int $editingId = null;

    #[Validate('required|exists:clients,id')]
    public ?int $client_id = null;

    #[Validate('required|exists:barbers,id')]
    public ?int $barber_id = null;

    #[Validate('required|exists:services,id')]
    public ?int $service_id = null;

    #[Validate('required|date')]
    public string $starts_at = '';

    #[Validate('required|in:pending,confirmed,completed,cancelled')]
    public string $status = 'pending';

    public bool $showForm = false;

    public function mount(): void
    {
        $this->date = Carbon::now()->toDateString();
    }

    #[Computed]
    public function appointments()
    {
        return Appointment::query()
            ->with(['client', 'barber', 'service'])
            ->forDay(Carbon::parse($this->date))
            ->orderBy('starts_at')
            ->get();
    }

    #[Computed]
    public function clients()
    {
        return Client::query()->orderBy('name')->get();
    }

    #[Computed]
    public function barbers()
    {
        return Barber::query()->active()->orderBy('name')->get();
    }

    #[Computed]
    public function services()
    {
        return Service::query()->active()->orderBy('name')->get();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->starts_at = Carbon::parse($this->date)->setTime(10, 0)->format('Y-m-d\TH:i');
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $appointment = Appointment::findOrFail($id);
        $this->editingId = $appointment->id;
        $this->client_id = $appointment->client_id;
        $this->barber_id = $appointment->barber_id;
        $this->service_id = $appointment->service_id;
        $this->starts_at = $appointment->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->status = $appointment->status->value;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate();

        $service = Service::findOrFail($data['service_id']);
        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = $startsAt->copy()->addMinutes($service->duration_minutes);

        $payload = [
            'client_id' => $data['client_id'],
            'barber_id' => $data['barber_id'],
            'service_id' => $data['service_id'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => AppointmentStatus::from($data['status']),
        ];

        if ($this->editingId) {
            Appointment::findOrFail($this->editingId)->update($payload);
        } else {
            Appointment::create($payload + ['notified_30min' => false]);
        }

        unset($this->appointments);
        $this->resetForm();
        $this->showForm = false;
    }

    public function delete(int $id): void
    {
        Appointment::findOrFail($id)->delete();
        unset($this->appointments);
    }

    public function markCompleted(int $id): void
    {
        Appointment::whereKey($id)->update(['status' => AppointmentStatus::Completed]);

        $appointment = Appointment::find($id);
        if ($appointment) {
            $appointment->client?->forceFill(['last_visit_at' => $appointment->starts_at])->save();
        }

        unset($this->appointments);
    }

    public function markCancelled(int $id): void
    {
        Appointment::whereKey($id)->update(['status' => AppointmentStatus::Cancelled]);
        unset($this->appointments);
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'client_id', 'barber_id', 'service_id', 'starts_at']);
        $this->status = 'pending';
        $this->resetErrorBag();
    }
}; ?>

<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-3xl font-bold tracking-tight">Записи</h1>
        <div class="flex items-center gap-2">
            <label for="date" class="text-sm text-zinc-600">Дата</label>
            <input id="date" type="date" wire:model.live="date"
                   class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
            <button type="button" wire:click="openCreate"
                    class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800">
                Добавить
            </button>
        </div>
    </div>

    @if ($showForm)
        <form wire:submit="save"
              class="mb-6 grid gap-4 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-zinc-700">Клиент</label>
                <select wire:model="client_id"
                        class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
                    <option value="">— Выберите клиента —</option>
                    @foreach ($this->clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }} ({{ $client->formattedPhone }})</option>
                    @endforeach
                </select>
                @error('client_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700">Мастер</label>
                <select wire:model="barber_id"
                        class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
                    <option value="">— Выберите мастера —</option>
                    @foreach ($this->barbers as $barber)
                        <option value="{{ $barber->id }}">{{ $barber->name }}</option>
                    @endforeach
                </select>
                @error('barber_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700">Услуга</label>
                <select wire:model="service_id"
                        class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
                    <option value="">— Выберите услугу —</option>
                    @foreach ($this->services as $service)
                        <option value="{{ $service->id }}">
                            {{ $service->name }} · {{ $service->duration_minutes }} мин · {{ $service->formattedPrice }}
                        </option>
                    @endforeach
                </select>
                @error('service_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700">Дата и время</label>
                <input type="datetime-local" wire:model="starts_at"
                       class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
                @error('starts_at') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700">Статус</label>
                <select wire:model="status"
                        class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
                    @foreach (AppointmentStatus::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
                @error('status') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2 flex items-center justify-end gap-2">
                <button type="button" wire:click="cancel"
                        class="rounded-lg border border-zinc-200 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50">
                    Отмена
                </button>
                <button type="submit"
                        class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                    {{ $editingId ? 'Сохранить' : 'Создать' }}
                </button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-zinc-200 text-sm">
            <thead class="bg-zinc-50">
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    <th class="px-3 py-3 sm:px-4">Время</th>
                    <th class="px-3 py-3 sm:px-4">Клиент</th>
                    <th class="hidden px-3 py-3 md:table-cell sm:px-4">Мастер</th>
                    <th class="hidden px-3 py-3 md:table-cell sm:px-4">Услуга</th>
                    <th class="px-3 py-3 sm:px-4">Статус</th>
                    <th class="px-3 py-3 text-right sm:px-4">Действия</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($this->appointments as $appointment)
                    <tr>
                        <td class="whitespace-nowrap px-3 py-3 font-medium sm:px-4">
                            {{ $appointment->starts_at->format('H:i') }}–{{ $appointment->ends_at->format('H:i') }}
                        </td>
                        <td class="px-3 py-3 sm:px-4">
                            <div class="font-medium">{{ $appointment->client?->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $appointment->client?->formattedPhone }}</div>
                            <div class="mt-0.5 text-xs text-zinc-400 md:hidden">
                                {{ $appointment->barber?->name }} · {{ $appointment->service?->name }}
                            </div>
                        </td>
                        <td class="hidden px-3 py-3 md:table-cell sm:px-4">{{ $appointment->barber?->name }}</td>
                        <td class="hidden px-3 py-3 md:table-cell sm:px-4">
                            <div>{{ $appointment->service?->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $appointment->service?->formattedPrice }}</div>
                        </td>
                        <td class="px-3 py-3 sm:px-4">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $appointment->status->badgeClasses() }}">
                                {{ $appointment->status->label() }}
                            </span>
                        </td>
                        <td class="px-3 py-3 sm:px-4">
                            <div class="flex flex-wrap items-center justify-end gap-1.5 sm:gap-2">
                                @if (in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true))
                                    <button type="button" wire:click="markCompleted({{ $appointment->id }})"
                                            class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">
                                        Завершено
                                    </button>
                                    <button type="button" wire:click="markCancelled({{ $appointment->id }})"
                                            wire:confirm="Отменить запись?"
                                            class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-rose-700">
                                        Отменить
                                    </button>
                                @endif
                                <button type="button" wire:click="edit({{ $appointment->id }})"
                                        class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50">
                                    Изменить
                                </button>
                                <button type="button" wire:click="delete({{ $appointment->id }})"
                                        wire:confirm="Удалить запись?"
                                        class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-rose-700">
                                    Удалить
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
            <td colspan="6" class="px-4 py-10 text-center text-zinc-500">
                            На эту дату записей нет.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
