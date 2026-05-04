<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Support\Carbon;
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
}; ?>

<div>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-3xl font-bold tracking-tight">Записи</h1>
        <div class="flex items-center gap-2">
            <label for="date" class="text-sm text-zinc-600">Дата</label>
            <input id="date" type="date" wire:model.live="date"
                   class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-zinc-200 text-sm">
            <thead class="bg-zinc-50">
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    <th class="px-4 py-3">Время</th>
                    <th class="px-4 py-3">Клиент</th>
                    <th class="px-4 py-3">Мастер</th>
                    <th class="px-4 py-3">Услуга</th>
                    <th class="px-4 py-3">Статус</th>
                    <th class="px-4 py-3 text-right">Действия</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
                @forelse ($this->appointments as $appointment)
                    <tr>
                        <td class="px-4 py-3 font-medium">
                            {{ $appointment->starts_at->format('H:i') }}–{{ $appointment->ends_at->format('H:i') }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $appointment->client?->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $appointment->client?->formattedPhone }}</div>
                        </td>
                        <td class="px-4 py-3">{{ $appointment->barber?->name }}</td>
                        <td class="px-4 py-3">
                            <div>{{ $appointment->service?->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $appointment->service?->formattedPrice }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $appointment->status->badgeClasses() }}">
                                {{ $appointment->status->label() }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                @if (in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true))
                                    <button type="button"
                                            wire:click="markCompleted({{ $appointment->id }})"
                                            class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700">
                                        Завершено
                                    </button>
                                    <button type="button"
                                            wire:click="markCancelled({{ $appointment->id }})"
                                            wire:confirm="Отменить запись?"
                                            class="rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-rose-700">
                                        Отменить
                                    </button>
                                @else
                                    <span class="text-xs text-zinc-400">—</span>
                                @endif
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
