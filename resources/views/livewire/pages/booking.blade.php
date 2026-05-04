<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    public int $step = 1;

    public ?int $serviceId = null;

    public ?int $barberId = null;

    public ?string $date = null;

    public ?string $time = null;

    public string $name = '';

    public string $phone = '';

    public ?int $confirmedAppointmentId = null;

    public function mount(): void
    {
        $this->date = Carbon::now()->toDateString();
    }

    #[Computed]
    public function services()
    {
        return Service::active()->orderBy('name')->get();
    }

    #[Computed]
    public function barbers()
    {
        return Barber::active()->orderBy('name')->get();
    }

    #[Computed]
    public function selectedService(): ?Service
    {
        return $this->serviceId ? Service::find($this->serviceId) : null;
    }

    #[Computed]
    public function selectedBarber(): ?Barber
    {
        return $this->barberId ? Barber::find($this->barberId) : null;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function availableSlots(): array
    {
        if (! $this->serviceId || ! $this->barberId || ! $this->date) {
            return [];
        }

        $service = $this->selectedService;
        $barber = $this->selectedBarber;

        if (! $service || ! $barber) {
            return [];
        }

        $day = Carbon::parse($this->date);
        $dayKey = strtolower($day->format('D'));
        $window = $barber->schedule[$dayKey] ?? null;

        if (! is_array($window)) {
            return [];
        }

        [$open, $close] = $window;
        $start = $day->copy()->setTimeFromTimeString($open);
        $end = $day->copy()->setTimeFromTimeString($close);

        $now = Carbon::now();
        if ($day->isToday() && $start->lt($now)) {
            $start = $now->copy()->ceilMinutes(30);
        }

        $busy = Appointment::query()
            ->where('barber_id', $barber->id)
            ->active()
            ->forDay($day)
            ->get(['starts_at', 'ends_at']);

        $slots = [];
        $cursor = $start->copy();
        $duration = (int) $service->duration_minutes;

        while ($cursor->copy()->addMinutes($duration)->lte($end)) {
            $slotEnd = $cursor->copy()->addMinutes($duration);

            $clash = $busy->contains(function ($a) use ($cursor, $slotEnd) {
                return $cursor->lt($a->ends_at) && $slotEnd->gt($a->starts_at);
            });

            if (! $clash) {
                $slots[] = [
                    'value' => $cursor->format('H:i'),
                    'label' => $cursor->format('H:i'),
                ];
            }

            $cursor->addMinutes(30);
        }

        return $slots;
    }

    public function selectService(int $id): void
    {
        $this->serviceId = $id;
        $this->step = 2;
    }

    public function selectBarber(int $id): void
    {
        $this->barberId = $id;
        $this->step = 3;
    }

    public function selectTime(string $time): void
    {
        $this->time = $time;
        $this->step = 4;
    }

    public function back(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function confirm(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string'],
            'serviceId' => ['required', 'exists:services,id'],
            'barberId' => ['required', 'exists:barbers,id'],
            'date' => ['required', 'date'],
            'time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
        ], attributes: [
            'name' => 'имя',
            'phone' => 'телефон',
            'time' => 'время',
            'date' => 'дата',
        ]);

        $normalized = Client::normalizePhone($this->phone);
        if ($normalized === null) {
            $this->addError('phone', 'Введите корректный номер: 998XXXXXXXXX');

            return;
        }

        $service = $this->selectedService;
        $barber = $this->selectedBarber;
        if (! $service || ! $barber) {
            $this->addError('serviceId', 'Выберите услугу и мастера заново.');
            $this->step = 1;

            return;
        }

        $startsAt = Carbon::parse($this->date.' '.$this->time);
        $endsAt = $startsAt->copy()->addMinutes((int) $service->duration_minutes);

        $clash = Appointment::query()
            ->where('barber_id', $barber->id)
            ->active()
            ->where(function ($q) use ($startsAt, $endsAt) {
                $q->where('starts_at', '<', $endsAt)
                    ->where('ends_at', '>', $startsAt);
            })
            ->exists();

        if ($clash) {
            $this->addError('time', 'Это время только что заняли. Выберите другое.');
            $this->time = null;
            $this->step = 3;

            return;
        }

        $client = Client::firstOrCreate(
            ['phone' => $normalized],
            ['name' => $this->name],
        );

        if ($client->name !== $this->name && $this->name !== '') {
            $client->forceFill(['name' => $this->name])->save();
        }

        $appointment = Appointment::create([
            'client_id' => $client->id,
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => AppointmentStatus::Confirmed,
            'notified_30min' => false,
        ]);

        $this->confirmedAppointmentId = $appointment->id;
        $this->step = 5;
    }

    public function reset_flow(): void
    {
        $this->reset(['serviceId', 'barberId', 'time', 'name', 'phone', 'confirmedAppointmentId']);
        $this->step = 1;
    }
}; ?>

<div>
    <h1 class="mb-6 text-3xl font-bold tracking-tight">Онлайн-запись</h1>

    @if ($step < 5)
        <ol class="mb-8 flex items-center gap-2 text-xs font-medium text-zinc-500">
            @foreach (['Услуга', 'Мастер', 'Время', 'Контакты'] as $i => $label)
                @php($n = $i + 1)
                <li class="flex items-center gap-2">
                    <span @class([
                        'flex h-6 w-6 items-center justify-center rounded-full',
                        'bg-zinc-900 text-white' => $step >= $n,
                        'bg-zinc-200 text-zinc-500' => $step < $n,
                    ])>{{ $n }}</span>
                    <span @class([
                        'text-zinc-900' => $step >= $n,
                    ])>{{ $label }}</span>
                    @if (! $loop->last)
                        <span class="h-px w-6 bg-zinc-300"></span>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    @if ($step === 1)
        <h2 class="mb-4 text-lg font-semibold">Выберите услугу</h2>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @forelse ($this->services as $service)
                <button type="button"
                        wire:click="selectService({{ $service->id }})"
                        class="flex items-center justify-between rounded-xl border border-zinc-200 bg-white px-4 py-4 text-left shadow-sm transition hover:border-zinc-900">
                    <div>
                        <div class="font-semibold">{{ $service->name }}</div>
                        <div class="text-sm text-zinc-500">{{ $service->duration_minutes }} мин</div>
                    </div>
                    <div class="font-semibold">{{ $service->formattedPrice }}</div>
                </button>
            @empty
                <div class="text-zinc-500">Услуги не настроены.</div>
            @endforelse
        </div>
    @endif

    @if ($step === 2)
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-semibold">Выберите мастера</h2>
            <button type="button" wire:click="back" class="text-sm text-zinc-500 hover:text-zinc-900">← Назад</button>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($this->barbers as $barber)
                <button type="button"
                        wire:click="selectBarber({{ $barber->id }})"
                        class="rounded-xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:border-zinc-900">
                    <div class="font-semibold">{{ $barber->name }}</div>
                    <div class="text-sm text-zinc-500">{{ $barber->specialization }}</div>
                </button>
            @empty
                <div class="text-zinc-500">Мастера не настроены.</div>
            @endforelse
        </div>
    @endif

    @if ($step === 3)
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-semibold">Выберите дату и время</h2>
            <button type="button" wire:click="back" class="text-sm text-zinc-500 hover:text-zinc-900">← Назад</button>
        </div>

        <div class="mb-4 flex items-center gap-3">
            <label class="text-sm text-zinc-600" for="date">Дата</label>
            <input id="date"
                   type="date"
                   wire:model.live="date"
                   min="{{ now()->toDateString() }}"
                   max="{{ now()->addDays(30)->toDateString() }}"
                   class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
        </div>

        @if (count($this->availableSlots) === 0)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                В этот день у мастера нет свободного времени. Выберите другую дату.
            </div>
        @else
            <div class="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6">
                @foreach ($this->availableSlots as $slot)
                    <button type="button"
                            wire:click="selectTime('{{ $slot['value'] }}')"
                            class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium transition hover:border-zinc-900">
                        {{ $slot['label'] }}
                    </button>
                @endforeach
            </div>
        @endif
    @endif

    @if ($step === 4)
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-semibold">Ваши контакты</h2>
            <button type="button" wire:click="back" class="text-sm text-zinc-500 hover:text-zinc-900">← Назад</button>
        </div>

        <div class="mb-4 rounded-xl border border-zinc-200 bg-white p-4 text-sm">
            <div><span class="text-zinc-500">Услуга:</span> <strong>{{ $this->selectedService?->name }}</strong> ({{ $this->selectedService?->formattedPrice }})</div>
            <div><span class="text-zinc-500">Мастер:</span> <strong>{{ $this->selectedBarber?->name }}</strong></div>
            <div><span class="text-zinc-500">Время:</span> <strong>{{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('d MMMM') }}, {{ $time }}</strong></div>
        </div>

        <form wire:submit.prevent="confirm" class="space-y-4">
            <div>
                <label for="name" class="mb-1 block text-sm font-medium">Имя</label>
                <input id="name" type="text" wire:model="name"
                       class="block w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
                @error('name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="phone" class="mb-1 block text-sm font-medium">Телефон</label>
                <input id="phone" type="tel" wire:model="phone" placeholder="998 90 123 45 67"
                       class="block w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
                @error('phone') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-zinc-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800 disabled:opacity-50"
                    wire:loading.attr="disabled">
                <span wire:loading.remove>Подтвердить запись</span>
                <span wire:loading>Отправляем…</span>
            </button>
        </form>
    @endif

    @if ($step === 5)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-6 text-emerald-900">
            <h2 class="text-xl font-semibold">Готово! Запись подтверждена.</h2>
            <p class="mt-2 text-sm">Ждём вас {{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('d MMMM') }} в {{ $time }} к мастеру {{ $this->selectedBarber?->name }}.</p>
            <p class="mt-1 text-sm">За 30 минут до визита мы пришлём SMS-напоминание.</p>
            <button type="button" wire:click="reset_flow"
                    class="mt-4 inline-flex items-center rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">
                Записаться ещё раз
            </button>
        </div>
    @endif
</div>
