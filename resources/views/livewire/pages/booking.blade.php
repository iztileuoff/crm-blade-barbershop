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
    /** Max booking submissions accepted per client IP within the decay window. */
    private const MAX_BOOKINGS_PER_MINUTE = 5;

    public int $step = 1;

    public ?int $serviceId = null;

    public ?int $barberId = null;

    public ?string $date = null;

    public ?string $time = null;

    public string $name = '';

    public string $phone = '';

    public string $birth_date = '';

    public ?int $confirmedAppointmentId = null;

    public function mount(): void
    {
        $this->date = Carbon::now()->toDateString();
    }

    /**
     * When a recognizable phone is typed, pre-fill name and birth date from an
     * existing client. Only empty fields are filled — never overwrite the user's
     * own input.
     */
    public function updatedPhone(): void
    {
        $normalized = Client::normalizePhone($this->phone);
        if ($normalized === null) {
            return;
        }

        $client = Client::where('phone', $normalized)->first();
        if (! $client) {
            return;
        }

        if ($this->name === '') {
            $this->name = (string) $client->name;
        }
        if ($this->birth_date === '' && $client->birth_date !== null) {
            $this->birth_date = $client->birth_date->toDateString();
        }
    }

    #[Computed]
    public function services()
    {
        return Service::active()->ordered()->get();
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
        $slots = [];
        for ($h = 0; $h <= 23; $h++) {
            $time = sprintf('%02d:00', $h);
            $slots[] = ['value' => $time, 'label' => $time];
        }

        return $slots;
    }

    /**
     * Slot values ('HH:00') that clash with the selected barber's active
     * appointments on the selected date, accounting for the service duration.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function takenSlots(): array
    {
        if (! $this->barberId || ! $this->date) {
            return [];
        }

        $duration = (int) ($this->selectedService?->duration_minutes ?? 60);

        $appointments = Appointment::query()
            ->where('barber_id', $this->barberId)
            ->active()
            ->forDay(Carbon::parse($this->date))
            ->get(['starts_at', 'ends_at']);

        $taken = [];
        foreach ($this->availableSlots as $slot) {
            $slotStart = Carbon::parse($this->date.' '.$slot['value']);
            $slotEnd = $slotStart->copy()->addMinutes($duration);

            foreach ($appointments as $appointment) {
                if ($appointment->starts_at < $slotEnd && $appointment->ends_at > $slotStart) {
                    $taken[] = $slot['value'];
                    break;
                }
            }
        }

        return $taken;
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
        $throttleKey = 'booking:'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_BOOKINGS_PER_MINUTE)) {
            $this->addError('phone', __('booking.validation.too_many'));

            return;
        }

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string'],
            'birth_date' => ['nullable', 'date'],
            'serviceId' => ['required', 'exists:services,id'],
            'barberId' => ['required', 'exists:barbers,id'],
            'date' => ['required', 'date'],
            'time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
        ], attributes: [
            'name' => __('booking.validation.name'),
            'phone' => __('booking.validation.phone'),
            'birth_date' => __('booking.validation.birth_date'),
            'time' => __('booking.validation.time'),
            'date' => __('booking.validation.date'),
        ]);

        $normalized = Client::normalizePhone($this->phone);
        if ($normalized === null) {
            $this->addError('phone', __('booking.validation.invalid_phone'));

            return;
        }

        $service = $this->selectedService;
        $barber = $this->selectedBarber;
        if (! $service || ! $barber) {
            $this->addError('serviceId', __('booking.validation.reselect'));
            $this->step = 1;

            return;
        }

        $startsAt = Carbon::parse($this->date.' '.$this->time);
        $endsAt = $startsAt->copy()->addMinutes((int) $service->duration_minutes);

        $client = Client::firstOrCreate(
            ['phone' => $normalized],
            ['name' => $this->name, 'birth_date' => $this->birth_date ?: null],
        );

        $updates = [];
        if ($client->name !== $this->name && $this->name !== '') {
            $updates['name'] = $this->name;
        }
        if ($this->birth_date && $client->birth_date === null) {
            $updates['birth_date'] = $this->birth_date;
        }
        if ($updates !== []) {
            $client->forceFill($updates)->save();
        }

        $servicePrice = $barber->priceForService($service->id) ?? $barber->price ?? 0;

        $appointment = Appointment::create([
            'client_id' => $client->id,
            'barber_id' => $barber->id,
            'price' => $servicePrice,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => AppointmentStatus::Pending,
            'notified_30min' => false,
        ]);

        $appointment->services()->sync([$service->id => ['amount' => $servicePrice]]);

        RateLimiter::hit($throttleKey, 60);

        $this->confirmedAppointmentId = $appointment->id;
        $this->step = 5;
    }

    public function reset_flow(): void
    {
        $this->reset(['serviceId', 'barberId', 'time', 'name', 'phone', 'birth_date', 'confirmedAppointmentId']);
        $this->step = 1;
    }
}; ?>

<div class="mx-auto w-full max-w-lg">
    {{-- Step indicator --}}
    @if ($step < 5)
        <div class="mb-6 flex items-center justify-between">
            @foreach ([__('booking.steps.service'), __('booking.steps.barber'), __('booking.steps.time'), __('booking.steps.details')] as $i => $label)
                @php($n = $i + 1)
                <div class="flex flex-col items-center gap-1.5">
                    <div @class([
                        'flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold transition-all duration-300',
                        'bg-brass text-black shadow-lg shadow-brass/25' => $step >= $n,
                        'bg-content/[0.06] text-content/25' => $step < $n,
                    ])>{{ $n }}</div>
                    <span @class([
                        'text-[10px] font-semibold tracking-wide transition-colors',
                        'text-brass-ink' => $step >= $n,
                        'text-content/25' => $step < $n,
                    ])>{{ $label }}</span>
                </div>
                @if (! $loop->last)
                    <div @class([
                        'mb-5 h-px flex-1 mx-2 transition-all duration-500',
                        'bg-brass/40' => $step > $n,
                        'bg-content/[0.06]' => $step <= $n,
                    ])></div>
                @endif
            @endforeach
        </div>
    @endif

    {{-- STEP 1: Services --}}
    @if ($step === 1)
        <div class="animate-fade-in-up">
            <h2 class="mb-1 text-lg font-bold">{{ __('booking.service.title') }}</h2>
            <p class="mb-5 text-sm text-content/40">{{ __('booking.service.subtitle') }}</p>
            <div class="space-y-2.5">
                @forelse ($this->services as $service)
                    <button type="button" wire:key="bk-service-{{ $service->id }}"
                            wire:click="selectService({{ $service->id }})"
                            class="group flex w-full items-center justify-between rounded-2xl border border-content/[0.06] bg-content/[0.03] p-4 text-left transition-all duration-200 hover:border-brass/30 hover:bg-content/[0.06] active:scale-[0.98]">
                        <div class="flex items-center gap-3.5">
                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brass/10">
                                <x-service-icon :name="$service->icon" class="h-5 w-5 text-brass-ink" />
                            </div>
                            <div>
                                <div class="text-sm font-semibold">{{ $service->name }}</div>
                                <div class="text-xs text-content/35">{{ $service->duration_minutes }} {{ __('booking.minutes_short') }}</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-content/15 transition-all group-hover:translate-x-0.5 group-hover:text-brass-ink" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>
                        </div>
                    </button>
                @empty
                    <div class="rounded-2xl border border-content/[0.06] bg-content/[0.03] p-8 text-center text-sm text-content/30">
                        {{ __('booking.service.empty') }}
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- STEP 2: Barbers --}}
    @if ($step === 2)
        <div class="animate-fade-in-up">
            <button type="button" wire:click="back" class="mb-4 flex items-center gap-1 text-xs text-content/30 transition hover:text-content/60">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                {{ __('booking.back') }}
            </button>
            <h2 class="mb-1 text-lg font-bold">{{ __('booking.barber.title') }}</h2>
            <p class="mb-5 text-sm text-content/40">{{ __('booking.barber.subtitle') }}</p>
            <div class="grid grid-cols-2 gap-3">
                @forelse ($this->barbers as $barber)
                    @php($photo = $barber->photoUrl)
                    <button type="button" wire:key="bk-barber-{{ $barber->id }}"
                            wire:click="selectBarber({{ $barber->id }})"
                            class="group flex flex-col items-center rounded-2xl border border-content/[0.06] bg-content/[0.03] p-4 pb-3.5 text-center transition-all duration-200 hover:border-brass/30 hover:bg-content/[0.06] active:scale-[0.97]">
                        @if ($photo)
                            <img src="{{ $photo }}" alt="{{ $barber->name }}"
                                 class="mb-3 h-20 w-20 rounded-full object-cover ring-2 ring-content/10 transition-all group-hover:ring-brass/40">
                        @else
                            <div class="mb-3 flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-brass/20 to-brass/10 text-2xl font-bold text-brass-ink/70 ring-2 ring-content/10 transition-all group-hover:ring-brass/40">
                                {{ mb_strtoupper(mb_substr($barber->name, 0, 1)) }}
                            </div>
                        @endif
                        <div class="text-sm font-semibold">{{ $barber->name }}</div>
                        <div class="mt-0.5 text-[11px] text-content/35">{{ $barber->specialization?->name }}</div>
                        @if ($barber->price)
                            <div class="mt-1 text-xs font-bold text-brass-ink">{{ $barber->formattedPrice }}</div>
                        @endif
                    </button>
                @empty
                    <div class="col-span-2 rounded-2xl border border-content/[0.06] bg-content/[0.03] p-8 text-center text-sm text-content/30">
                        {{ __('booking.barber.empty') }}
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- STEP 3: Date & Time --}}
    @if ($step === 3)
        <div class="animate-fade-in-up">
            <button type="button" wire:click="back" class="mb-4 flex items-center gap-1 text-xs text-content/30 transition hover:text-content/60">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                {{ __('booking.back') }}
            </button>
            <h2 class="mb-1 text-lg font-bold">{{ __('booking.datetime.title') }}</h2>
            <p class="mb-5 text-sm text-content/40">{{ __('booking.datetime.subtitle') }}</p>

            {{-- Horizontal date picker --}}
            <div class="hide-scrollbar -mx-4 mb-5 flex gap-2 overflow-x-auto px-4 pb-1">
                @for ($i = 0; $i < 14; $i++)
                    @php($d = now()->addDays($i))
                    @php($isSelected = $date === $d->toDateString())
                    <button type="button"
                            wire:click="$set('date', '{{ $d->toDateString() }}')"
                            @class([
                                'flex shrink-0 flex-col items-center rounded-2xl px-3.5 py-2.5 transition-all duration-200',
                                'bg-brass text-black shadow-lg shadow-brass/20' => $isSelected,
                                'bg-content/[0.04] text-content/40 hover:bg-content/[0.08]' => ! $isSelected,
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
                                'text-brass-ink/60' => ! $isSelected,
                            ])>{{ __('booking.datetime.today') }}</span>
                        @endif
                    </button>
                @endfor
            </div>

            {{-- Time slots --}}
            @if (count($this->availableSlots) === 0)
                <div class="rounded-2xl border border-brass/10 bg-brass/5 p-5 text-center text-sm text-brass-ink/60">
                    {{ __('booking.datetime.no_slots') }}
                </div>
            @else
                @php($takenSlots = $this->takenSlots)
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    @foreach ($this->availableSlots as $slot)
                        @php($isTaken = in_array($slot['value'], $takenSlots, true))
                        <button type="button" wire:key="bk-slot-{{ $slot['value'] }}"
                                wire:click="selectTime('{{ $slot['value'] }}')"
                                @if ($isTaken) title="{{ __('booking.datetime.taken') }}" @endif
                                @class([
                                    'rounded-xl border px-3 py-2.5 text-sm font-semibold transition-all duration-200 active:scale-95',
                                    'border-danger/40 bg-danger/10 text-danger hover:bg-danger/20' => $isTaken,
                                    'border-content/[0.06] bg-content/[0.03] hover:border-brass/40 hover:bg-brass/10 hover:text-brass-ink' => ! $isTaken,
                                ])>
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
            <button type="button" wire:click="back" class="mb-4 flex items-center gap-1 text-xs text-content/30 transition hover:text-content/60">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                {{ __('booking.back') }}
            </button>
            <h2 class="mb-1 text-lg font-bold">{{ __('booking.confirm.title') }}</h2>
            <p class="mb-5 text-sm text-content/40">{{ __('booking.confirm.subtitle') }}</p>

            {{-- Booking summary --}}
            <div class="mb-5 rounded-2xl border border-content/[0.06] bg-content/[0.03] p-4">
                <div class="flex items-start justify-between border-b border-content/[0.06] pb-3">
                    <div>
                        <div class="text-sm font-semibold">{{ $this->selectedService?->name }}</div>
                        <div class="text-xs text-content/35">{{ $this->selectedService?->duration_minutes }} {{ __('booking.minutes_short') }}</div>
                    </div>
                    @if ($this->selectedBarber?->price)
                        <div class="text-sm font-bold text-brass-ink">{{ $this->selectedBarber?->formattedPrice }}</div>
                    @endif
                </div>
                <div class="flex items-center gap-3 pt-3">
                    @php($photo = $this->selectedBarber?->photoUrl)
                    @if ($photo)
                        <img src="{{ $photo }}" alt="" class="h-9 w-9 rounded-full object-cover ring-1 ring-content/10">
                    @else
                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brass/15 text-xs font-bold text-brass-ink">
                            {{ mb_strtoupper(mb_substr($this->selectedBarber?->name ?? '', 0, 1)) }}
                        </div>
                    @endif
                    <div class="flex-1">
                        <div class="text-sm font-medium">{{ $this->selectedBarber?->name }}</div>
                        <div class="text-xs text-content/35">{{ $this->selectedBarber?->specialization?->name }}</div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm font-semibold">{{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('d M') }}</div>
                        <div class="text-xs text-brass-ink">{{ $time }}</div>
                    </div>
                </div>
            </div>

            {{-- Contact form --}}
            <form wire:submit.prevent="confirm" class="space-y-3">
                <div>
                    <label for="name" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('booking.confirm.name') }}</label>
                    <input id="name" type="text" wire:model="name" placeholder="{{ __('booking.confirm.name_placeholder') }}"
                           class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                    @error('name') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="phone" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('booking.confirm.phone') }}</label>
                    <input id="phone" type="tel" inputmode="tel" autocomplete="tel" wire:model.blur="phone" placeholder="998 90 123 45 67"
                           class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                    @error('phone') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="birth_date" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('booking.confirm.birth_date') }} <span class="font-normal text-content/25">{{ __('booking.confirm.optional') }}</span></label>
                    <input id="birth_date" type="date" wire:model="birth_date"
                           class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 dark:[color-scheme:dark]">
                    @error('birth_date') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                </div>
                <button type="submit"
                        class="mt-1 w-full rounded-xl bg-gradient-to-r from-brass to-brass px-4 py-3.5 text-sm font-bold text-black shadow-lg shadow-brass/20 transition-all hover:shadow-brass/30 active:scale-[0.98] disabled:opacity-50"
                        wire:loading.attr="disabled">
                    <span wire:loading.remove>{{ __('booking.confirm.submit') }}</span>
                    <span wire:loading>{{ __('booking.confirm.submitting') }}</span>
                </button>
            </form>
        </div>
    @endif

    {{-- STEP 5: Success --}}
    @if ($step === 5)
        <div class="animate-fade-in-up py-8 text-center">
            <div class="animate-scale-in mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-full bg-success/15">
                <svg class="h-10 w-10 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
            </div>
            <h2 class="mb-2 text-xl font-bold">{{ __('booking.success.title') }}</h2>
            <p class="mx-auto max-w-xs text-sm text-content/40">
                {{ __('booking.success.message', [
                    'date' => \Illuminate\Support\Carbon::parse($date)->translatedFormat('d M'),
                    'time' => $time,
                    'barber' => $this->selectedBarber?->name,
                ]) }}
            </p>
            <p class="mt-1.5 text-xs text-content/25">{{ __('booking.success.sms_note') }}</p>

            <div class="mx-auto mt-6 max-w-xs rounded-2xl border border-content/[0.06] bg-content/[0.03] p-4 text-left text-sm">
                <div class="flex items-center justify-between">
                    <span class="text-content/40">{{ __('booking.success.service') }}</span>
                    <span class="font-medium">{{ $this->selectedService?->name }}</span>
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <span class="text-content/40">{{ __('booking.success.price') }}</span>
                    <span class="font-semibold text-brass-ink">{{ $this->selectedBarber?->formattedPrice ?: '—' }}</span>
                </div>
            </div>

            <button type="button" wire:click="reset_flow"
                    class="mt-6 inline-flex items-center gap-2 rounded-xl border border-content/[0.08] bg-content/[0.04] px-6 py-3 text-sm font-semibold transition hover:bg-content/[0.08] active:scale-[0.98]">
                <svg class="h-4 w-4 text-content/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" />
                </svg>
                {{ __('booking.success.again') }}
            </button>
        </div>
    @endif
</div>
