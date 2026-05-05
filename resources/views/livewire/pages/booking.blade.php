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
#[Layout('components.layouts.booking')]
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
        return Barber::active()->with(['specialization', 'media'])->orderBy('name')->get();
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
            'price' => $barber->price,
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
    {{-- Step indicator --}}
    @if ($step < 5)
        <div class="mb-6 flex items-center justify-between">
            @foreach (['Услуга', 'Мастер', 'Время', 'Данные'] as $i => $label)
                @php($n = $i + 1)
                <div class="flex flex-col items-center gap-1.5">
                    <div @class([
                        'flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold transition-all duration-300',
                        'bg-amber-500 text-black shadow-lg shadow-amber-500/25' => $step >= $n,
                        'bg-white/[0.06] text-white/25' => $step < $n,
                    ])>{{ $n }}</div>
                    <span @class([
                        'text-[10px] font-semibold tracking-wide transition-colors',
                        'text-amber-400' => $step >= $n,
                        'text-white/25' => $step < $n,
                    ])>{{ $label }}</span>
                </div>
                @if (! $loop->last)
                    <div @class([
                        'mb-5 h-px flex-1 mx-2 transition-all duration-500',
                        'bg-amber-500/40' => $step > $n,
                        'bg-white/[0.06]' => $step <= $n,
                    ])></div>
                @endif
            @endforeach
        </div>
    @endif

    {{-- STEP 1: Services --}}
    @if ($step === 1)
        <div class="animate-fade-in-up">
            <h2 class="mb-1 text-lg font-bold">Выберите услугу</h2>
            <p class="mb-5 text-sm text-white/40">Что бы вы хотели сделать?</p>
            <div class="space-y-2.5">
                @forelse ($this->services as $service)
                    <button type="button"
                            wire:click="selectService({{ $service->id }})"
                            class="group flex w-full items-center justify-between rounded-2xl border border-white/[0.06] bg-white/[0.03] p-4 text-left transition-all duration-200 hover:border-amber-500/30 hover:bg-white/[0.06] active:scale-[0.98]">
                        <div class="flex items-center gap-3.5">
                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-500/10">
                                <svg class="h-5 w-5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.3 24.3 0 0 1 4.5 0m0 0v5.714a2.25 2.25 0 0 0 .659 1.591L19 14.5M14.25 3.104c.251.023.501.05.75.082M19 14.5l-1.47 4.42a2.25 2.25 0 0 1-2.136 1.53H8.607a2.25 2.25 0 0 1-2.137-1.53L5 14.5m14 0H5" />
                                </svg>
                            </div>
                            <div>
                                <div class="text-sm font-semibold">{{ $service->name }}</div>
                                <div class="text-xs text-white/35">{{ $service->duration_minutes }} мин</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-white/15 transition-all group-hover:translate-x-0.5 group-hover:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>
                        </div>
                    </button>
                @empty
                    <div class="rounded-2xl border border-white/[0.06] bg-white/[0.03] p-8 text-center text-sm text-white/30">
                        Услуги пока не добавлены
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- STEP 2: Barbers --}}
    @if ($step === 2)
        <div class="animate-fade-in-up">
            <button type="button" wire:click="back" class="mb-4 flex items-center gap-1 text-xs text-white/30 transition hover:text-white/60">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                Назад
            </button>
            <h2 class="mb-1 text-lg font-bold">Выберите мастера</h2>
            <p class="mb-5 text-sm text-white/40">Кто будет вашим мастером?</p>
            <div class="grid grid-cols-2 gap-3">
                @forelse ($this->barbers as $barber)
                    @php($photo = $barber->photoUrl)
                    <button type="button"
                            wire:click="selectBarber({{ $barber->id }})"
                            class="group flex flex-col items-center rounded-2xl border border-white/[0.06] bg-white/[0.03] p-4 pb-3.5 text-center transition-all duration-200 hover:border-amber-500/30 hover:bg-white/[0.06] active:scale-[0.97]">
                        @if ($photo)
                            <img src="{{ $photo }}" alt="{{ $barber->name }}"
                                 class="mb-3 h-20 w-20 rounded-full object-cover ring-2 ring-white/10 transition-all group-hover:ring-amber-500/40">
                        @else
                            <div class="mb-3 flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-amber-500/20 to-amber-700/10 text-2xl font-bold text-amber-400/70 ring-2 ring-white/10 transition-all group-hover:ring-amber-500/40">
                                {{ mb_strtoupper(mb_substr($barber->name, 0, 1)) }}
                            </div>
                        @endif
                        <div class="text-sm font-semibold">{{ $barber->name }}</div>
                        <div class="mt-0.5 text-[11px] text-white/35">{{ $barber->specialization?->name }}</div>
                        @if ($barber->price)
                            <div class="mt-1 text-xs font-bold text-amber-400">{{ $barber->formattedPrice }}</div>
                        @endif
                    </button>
                @empty
                    <div class="col-span-2 rounded-2xl border border-white/[0.06] bg-white/[0.03] p-8 text-center text-sm text-white/30">
                        Мастера пока не добавлены
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- STEP 3: Date & Time --}}
    @if ($step === 3)
        <div class="animate-fade-in-up">
            <button type="button" wire:click="back" class="mb-4 flex items-center gap-1 text-xs text-white/30 transition hover:text-white/60">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                Назад
            </button>
            <h2 class="mb-1 text-lg font-bold">Дата и время</h2>
            <p class="mb-5 text-sm text-white/40">Когда вам удобно?</p>

            {{-- Horizontal date picker --}}
            <div class="hide-scrollbar -mx-4 mb-5 flex gap-2 overflow-x-auto px-4 pb-1">
                @for ($i = 0; $i < 14; $i++)
                    @php($d = now()->addDays($i))
                    @php($isSelected = $date === $d->toDateString())
                    <button type="button"
                            wire:click="$set('date', '{{ $d->toDateString() }}')"
                            @class([
                                'flex shrink-0 flex-col items-center rounded-2xl px-3.5 py-2.5 transition-all duration-200',
                                'bg-amber-500 text-black shadow-lg shadow-amber-500/20' => $isSelected,
                                'bg-white/[0.04] text-white/40 hover:bg-white/[0.08]' => ! $isSelected,
                            ])>
                        <span @class([
                            'text-[10px] font-semibold uppercase',
                            'text-black/50' => $isSelected,
                        ])>{{ $d->translatedFormat('D') }}</span>
                        <span class="text-lg font-bold leading-tight">{{ $d->day }}</span>
                        @if ($i === 0)
                            <span @class([
                                'text-[8px] font-bold uppercase tracking-wide',
                                'text-black/40' => $isSelected,
                                'text-amber-500/60' => ! $isSelected,
                            ])>Сегодня</span>
                        @endif
                    </button>
                @endfor
            </div>

            {{-- Time slots --}}
            @if (count($this->availableSlots) === 0)
                <div class="rounded-2xl border border-amber-500/10 bg-amber-500/5 p-5 text-center text-sm text-amber-200/60">
                    В этот день нет свободных окон. Попробуйте другую дату.
                </div>
            @else
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    @foreach ($this->availableSlots as $slot)
                        <button type="button"
                                wire:click="selectTime('{{ $slot['value'] }}')"
                                class="rounded-xl border border-white/[0.06] bg-white/[0.03] px-3 py-2.5 text-sm font-semibold transition-all duration-200 hover:border-amber-500/40 hover:bg-amber-500/10 hover:text-amber-400 active:scale-95">
                            {{ $slot['label'] }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- STEP 4: Contacts & Confirm --}}
    @if ($step === 4)
        <div class="animate-fade-in-up">
            <button type="button" wire:click="back" class="mb-4 flex items-center gap-1 text-xs text-white/30 transition hover:text-white/60">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                Назад
            </button>
            <h2 class="mb-1 text-lg font-bold">Подтверждение</h2>
            <p class="mb-5 text-sm text-white/40">Проверьте детали и введите контакты</p>

            {{-- Booking summary --}}
            <div class="mb-5 rounded-2xl border border-white/[0.06] bg-white/[0.03] p-4">
                <div class="flex items-start justify-between border-b border-white/[0.06] pb-3">
                    <div>
                        <div class="text-sm font-semibold">{{ $this->selectedService?->name }}</div>
                        <div class="text-xs text-white/35">{{ $this->selectedService?->duration_minutes }} мин</div>
                    </div>
                    @if ($this->selectedBarber?->price)
                        <div class="text-sm font-bold text-amber-400">{{ $this->selectedBarber?->formattedPrice }}</div>
                    @endif
                </div>
                <div class="flex items-center gap-3 pt-3">
                    @php($photo = $this->selectedBarber?->photoUrl)
                    @if ($photo)
                        <img src="{{ $photo }}" alt="" class="h-9 w-9 rounded-full object-cover ring-1 ring-white/10">
                    @else
                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-amber-500/15 text-xs font-bold text-amber-400">
                            {{ mb_strtoupper(mb_substr($this->selectedBarber?->name ?? '', 0, 1)) }}
                        </div>
                    @endif
                    <div class="flex-1">
                        <div class="text-sm font-medium">{{ $this->selectedBarber?->name }}</div>
                        <div class="text-xs text-white/35">{{ $this->selectedBarber?->specialization?->name }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm font-semibold">{{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('d MMM') }}</div>
                        <div class="text-xs text-amber-400">{{ $time }}</div>
                    </div>
                </div>
            </div>

            {{-- Contact form --}}
            <form wire:submit.prevent="confirm" class="space-y-3">
                <div>
                    <label for="name" class="mb-1.5 block text-xs font-semibold text-white/50">Имя</label>
                    <input id="name" type="text" wire:model="name" placeholder="Как вас зовут?"
                           class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white placeholder-white/20 outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20">
                    @error('name') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="phone" class="mb-1.5 block text-xs font-semibold text-white/50">Телефон</label>
                    <input id="phone" type="tel" wire:model="phone" placeholder="998 90 123 45 67"
                           class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white placeholder-white/20 outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20">
                    @error('phone') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                </div>
                <button type="submit"
                        class="mt-1 w-full rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 px-4 py-3.5 text-sm font-bold text-black shadow-lg shadow-amber-500/20 transition-all hover:shadow-amber-500/30 active:scale-[0.98] disabled:opacity-50"
                        wire:loading.attr="disabled">
                    <span wire:loading.remove>Подтвердить запись</span>
                    <span wire:loading>Бронируем…</span>
                </button>
            </form>
        </div>
    @endif

    {{-- STEP 5: Success --}}
    @if ($step === 5)
        <div class="animate-fade-in-up py-8 text-center">
            <div class="animate-scale-in mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-full bg-emerald-500/15">
                <svg class="h-10 w-10 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
            </div>
            <h2 class="mb-2 text-xl font-bold">Запись подтверждена!</h2>
            <p class="mx-auto max-w-xs text-sm text-white/40">
                Ждём вас {{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('d MMMM') }}
                в {{ $time }} к мастеру {{ $this->selectedBarber?->name }}.
            </p>
            <p class="mt-1.5 text-xs text-white/25">За 30 минут до визита придёт SMS-напоминание</p>

            <div class="mx-auto mt-6 max-w-xs rounded-2xl border border-white/[0.06] bg-white/[0.03] p-4 text-left text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-white/40">Услуга</span>
                    <span class="font-medium">{{ $this->selectedService?->name }}</span>
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <span class="text-white/40">Стоимость</span>
                    <span class="font-semibold text-amber-400">{{ $this->selectedBarber?->formattedPrice ?: '—' }}</span>
                </div>
            </div>

            <button type="button" wire:click="reset_flow"
                    class="mt-6 inline-flex items-center gap-2 rounded-xl border border-white/[0.08] bg-white/[0.04] px-6 py-3 text-sm font-semibold transition hover:bg-white/[0.08] active:scale-[0.98]">
                <svg class="h-4 w-4 text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                </svg>
                Записаться ещё раз
            </button>
        </div>
    @endif
</div>
