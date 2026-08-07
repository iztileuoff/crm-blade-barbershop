<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.booking')]
class extends Component
{
    /** Max booking submissions accepted per client IP within the decay window. */
    private const MAX_BOOKINGS_PER_MINUTE = 5;

    /** Max phone lookups accepted per client IP within the decay window. */
    private const MAX_LOOKUPS_PER_MINUTE = 20;

    /** Working hours used when the settings are missing or malformed. */
    private const DEFAULT_WORK_START_HOUR = 9;

    private const DEFAULT_WORK_END_HOUR = 21;

    /** Days shown in the date strip below — also the outer edge a URL-supplied date may name. */
    private const DATE_HORIZON_DAYS = 14;

    /**
     * Тот же горизонт для ленты дат: разметка не должна знать своё число
     * отдельно от проверки в mount(), иначе кнопка предложит дату, которую
     * перезагрузка отвергнет.
     */
    #[Computed]
    public function dateHorizonDays(): int
    {
        return self::DATE_HORIZON_DAYS;
    }

    /**
     * Контакты салона из Settings — некуда было деть на экране успеха и в
     * шапке (#76): телефон/адрес/бот админ заполняет, но их никто не читал.
     */
    #[Computed]
    public function shopPhone(): ?string
    {
        return Setting::get('shop_phone');
    }

    #[Computed]
    public function shopAddress(): ?string
    {
        return Setting::get('shop_address');
    }

    /**
     * Ссылка на бота вида https://t.me/username — админ хранит хендл в
     * settings.telegram как «@handle» или «handle», без домена.
     */
    #[Computed]
    public function shopTelegramUrl(): ?string
    {
        $handle = trim((string) Setting::get('telegram', ''));

        return $handle !== '' ? 'https://t.me/'.ltrim($handle, '@') : null;
    }

    /**
     * `#[Url]` makes every one of these attacker-supplied on first paint — this is
     * a public, unauthenticated page. `mount()` below re-derives each value against
     * the database before the view ever renders, so a crafted or stale link can only
     * ever settle on the nearest step the (validated) selection actually supports.
     */
    #[Url(history: true)]
    public int $step = 1;

    #[Url]
    public ?int $serviceId = null;

    #[Url]
    public ?int $barberId = null;

    #[Url]
    public ?string $date = null;

    #[Url]
    public ?string $time = null;

    public string $name = '';

    public string $phone = '';

    public string $birth_date = '';

    /** True when the last phone lookup matched an existing client. */
    public bool $clientFound = false;

    public ?int $confirmedAppointmentId = null;

    public function mount(): void
    {
        $this->serviceId = $this->validServiceId($this->serviceId);

        $this->barberId = $this->serviceId !== null
            ? $this->validBarberId($this->barberId)
            : null;

        $this->date = $this->validDateWithinHorizon($this->date) ?? Carbon::now()->toDateString();

        $this->time = $this->barberId !== null && $this->isSelectableSlot($this->time)
            ? $this->time
            : null;

        $this->step = match (true) {
            $this->time !== null => 4,
            $this->barberId !== null => 3,
            $this->serviceId !== null => 2,
            default => 1,
        };
    }

    /**
     * A service id from the URL is only trusted while it still names an active
     * service; a stale or invented id is dropped rather than reaching the
     * price/duration lookups further down.
     */
    private function validServiceId(?int $id): ?int
    {
        if ($id === null) {
            return null;
        }

        return Service::active()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * Mirrors {@see validServiceId()} for barbers. Forced to null whenever the
     * service itself was dropped — the click path never lets a barber be chosen
     * before a service is.
     */
    private function validBarberId(?int $id): ?int
    {
        if ($id === null) {
            return null;
        }

        return Barber::active()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * A date from the URL is only trusted when it is a real calendar date inside
     * the window the strip itself offers: nothing in the past, nothing past the
     * visible horizon.
     */
    private function validDateWithinHorizon(?string $date): ?string
    {
        if ($date === null || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) {
            return null;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        $today = Carbon::now()->startOfDay();
        $parsed = Carbon::createFromDate((int) $parts[1], (int) $parts[2], (int) $parts[3])->startOfDay();

        if ($parsed->lt($today) || $parsed->gt($today->copy()->addDays(self::DATE_HORIZON_DAYS - 1))) {
            return null;
        }

        return $date;
    }

    /**
     * The name/birth fields only become editable once a valid phone is entered.
     */
    #[Computed]
    public function phoneReady(): bool
    {
        return Client::normalizePhone($this->phone) !== null;
    }

    /**
     * Record whether the phone already belongs to a client — nothing more.
     *
     * The stored name and birth date must never reach the browser: this page is
     * public and unauthenticated, so anyone could type a stranger's number and
     * read their card. Merging with the saved client happens server-side in
     * {@see confirm()}. The lookup is throttled per IP so the flag alone cannot
     * be used to enumerate which numbers are in the base.
     */
    public function updatedPhone(): void
    {
        $normalized = Client::normalizePhone($this->phone);

        if ($normalized === null) {
            $this->clientFound = false;

            return;
        }

        $throttleKey = 'booking-lookup:'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOOKUPS_PER_MINUTE)) {
            $this->clientFound = false;

            return;
        }

        RateLimiter::hit($throttleKey, 60);

        $this->clientFound = Client::where('phone', $normalized)->exists();
    }

    #[Computed]
    public function services()
    {
        return Service::active()->ordered()->get();
    }

    #[Computed]
    public function barbers()
    {
        return Barber::active()->with(['specialization', 'media', 'services'])->orderBy('name')->get();
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
     * Price of the service being booked with this barber — the very number that
     * lands in the appointment. Showing the barber's base `price` instead would
     * quote one sum on screen and charge another in the chair.
     */
    #[Computed]
    public function quotedPrice(): ?int
    {
        return $this->selectedBarber?->priceForService($this->serviceId);
    }

    #[Computed]
    public function formattedQuotedPrice(): ?string
    {
        $price = $this->quotedPrice;

        return $price === null ? null : number_format($price, 0, '.', ' ').' '.__('common.currency');
    }

    /**
     * The chosen day, guarded. `$date` is a client-writable field, so a crafted
     * value would otherwise crash Carbon::parse while rendering the slot grid.
     */
    #[Computed]
    public function selectedDate(): Carbon
    {
        try {
            return Carbon::parse($this->date)->startOfDay();
        } catch (Throwable) {
            return Carbon::now()->startOfDay();
        }
    }

    /**
     * Hourly slots inside the shop's working hours. For today every hour that
     * has already started is dropped — the grid must never offer a time that
     * has gone by.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function availableSlots(): array
    {
        [$startHour, $endHour] = $this->effectiveWorkingHours();

        $now = Carbon::now();
        $isToday = $this->selectedDate->isSameDay($now);

        $slots = [];
        for ($h = $startHour; $h < $endHour; $h++) {
            if ($isToday && $h <= $now->hour) {
                continue;
            }

            $time = sprintf('%02d:00', $h);
            $slots[] = ['value' => $time, 'label' => $time];
        }

        return $slots;
    }

    /**
     * Opening and closing hour from the settings. A slot must start early enough
     * to be served before closing, hence the range is half-open: `work_end` is
     * itself never offered. An inverted or malformed range falls back to the
     * defaults so a broken setting cannot empty the booking page.
     *
     * @return array{0: int, 1: int}
     */
    private function workingHours(): array
    {
        $start = $this->settingHour('work_start', self::DEFAULT_WORK_START_HOUR);
        $end = $this->settingHour('work_end', self::DEFAULT_WORK_END_HOUR);

        if ($end <= $start) {
            return [self::DEFAULT_WORK_START_HOUR, self::DEFAULT_WORK_END_HOUR];
        }

        return [$start, $end];
    }

    private function settingHour(string $key, int $default): int
    {
        $value = (string) Setting::get($key, '');

        if (preg_match('/^(\d{1,2}):\d{2}$/', $value, $matches) && (int) $matches[1] <= 23) {
            return (int) $matches[1];
        }

        return $default;
    }

    /**
     * Salon hours narrowed to the selected barber's own schedule for the
     * chosen weekday (#73) — the barber's schedule only ever tightens the
     * window {@see workingHours()} gives, never widens it. A barber with no
     * entry for that day — including every barber whose `schedule` predates
     * this feature — falls back to the salon hours unchanged, so existing
     * data keeps behaving exactly as it did before. A day marked as a day
     * off collapses to an empty (already-closed) range.
     *
     * @return array{0: int, 1: int}
     */
    private function effectiveWorkingHours(): array
    {
        [$salonStart, $salonEnd] = $this->workingHours();

        $barber = $this->selectedBarber;

        if (! $barber) {
            return [$salonStart, $salonEnd];
        }

        $day = Barber::WEEKDAYS[$this->selectedDate->dayOfWeekIso - 1];
        $window = $barber->scheduleWindowForDay($day);

        if ($window === null) {
            return [$salonStart, $salonEnd];
        }

        if ($window === 'off') {
            return [$salonStart, $salonStart];
        }

        // Slots are whole hours (see availableSlots()), so the barber's own
        // minutes round toward the narrower side: a start rounds up (not in
        // yet at the top of that hour), an end rounds down (already gone by
        // the top of that hour) — the same hour-only precision workingHours()
        // already applies to the salon-wide setting.
        $barberStart = $this->hourBoundary($window['start'], roundUp: true);
        $barberEnd = $this->hourBoundary($window['end'], roundUp: false);

        $start = max($salonStart, $barberStart);
        $end = max($start, min($salonEnd, $barberEnd));

        return [$start, $end];
    }

    private function hourBoundary(string $time, bool $roundUp): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $roundUp && $minute > 0 ? min($hour + 1, 24) : $hour;
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
            ->forDay($this->selectedDate)
            ->get(['starts_at', 'ends_at']);

        $taken = [];
        foreach ($this->availableSlots as $slot) {
            $slotStart = $this->selectedDate->copy()->setTimeFromTimeString($slot['value']);
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
        if (! $this->isSelectableSlot($time)) {
            return;
        }

        $this->time = $time;
        $this->step = 4;
    }

    /**
     * A slot may be picked only while it is still offered (inside the working
     * hours and in the future) and still free for the chosen barber.
     */
    private function isSelectableSlot(?string $time): bool
    {
        if ($time === null) {
            return false;
        }

        return in_array($time, array_column($this->availableSlots, 'value'), true)
            && ! in_array($time, $this->takenSlots, true);
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

        if (! $this->isSelectableSlot($this->time)) {
            $this->rejectSlot();

            return;
        }

        $startsAt = $this->selectedDate->copy()->setTimeFromTimeString($this->time);
        $endsAt = $startsAt->copy()->addMinutes((int) $service->duration_minutes);

        $servicePrice = $barber->priceForService($service->id) ?? $barber->price ?? 0;

        $appointment = DB::transaction(function () use ($barber, $service, $servicePrice, $normalized, $startsAt, $endsAt): ?Appointment {
            $isTaken = Appointment::query()
                ->where('barber_id', $barber->id)
                ->active()
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->lockForUpdate()
                ->exists();

            if ($isTaken) {
                return null;
            }

            $client = Client::firstOrCreate(
                ['phone' => $normalized],
                ['name' => $this->name, 'birth_date' => $this->birth_date ?: null],
            );

            $this->fillClientGaps($client);
            $this->rememberBookingLocale($client);

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

            return $appointment;
        });

        if ($appointment === null) {
            $this->rejectSlot();

            return;
        }

        RateLimiter::hit($throttleKey, 60);

        $this->confirmedAppointmentId = $appointment->id;
        $this->step = 5;
    }

    /**
     * Send the guest back to the time grid: the slot was booked, closed or ran
     * out from under them between picking it and confirming.
     */
    private function rejectSlot(): void
    {
        $this->time = null;
        $this->step = 3;
        $this->addError('time', __('booking.validation.slot_taken'));

        unset($this->availableSlots, $this->takenSlots);
    }

    /**
     * Fill only what the client's card is missing. The public form no longer
     * shows what we already store, so a guest's typing must not overwrite the
     * name or birth date of an existing client.
     */
    private function fillClientGaps(Client $client): void
    {
        $updates = [];

        if ((string) $client->name === '' && $this->name !== '') {
            $updates['name'] = $this->name;
        }

        if ($client->birth_date === null && $this->birth_date !== '') {
            $updates['birth_date'] = $this->birth_date;
        }

        if ($updates !== []) {
            $client->forceFill($updates)->save();
        }
    }

    /**
     * Unlike {@see fillClientGaps()}, the language is overwritten on every
     * booking — not just filled once: the site the client is booking from
     * right now is the most current signal of which language reaches them,
     * for the bot and for SMS (#76).
     */
    private function rememberBookingLocale(Client $client): void
    {
        $locale = app()->getLocale();

        if ($client->locale !== $locale) {
            $client->forceFill(['locale' => $locale])->save();
        }
    }

    public function reset_flow(): void
    {
        $this->reset(['serviceId', 'barberId', 'time', 'name', 'phone', 'birth_date', 'clientFound', 'confirmedAppointmentId']);
        $this->step = 1;
    }
}; ?>

<div class="mx-auto w-full max-w-lg">
    {{-- Step indicator --}}
    @if ($step < 5)
        <nav aria-label="{{ __('booking.steps.nav_label') }}" class="mb-6 flex items-center justify-between">
            @foreach ([__('booking.steps.service'), __('booking.steps.barber'), __('booking.steps.time'), __('booking.steps.details')] as $i => $label)
                @php($n = $i + 1)
                <div class="flex flex-col items-center gap-1.5" @if ($step === $n) aria-current="step" @endif>
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
        </nav>
    @endif

    {{-- STEP 1: Services --}}
    @if ($step === 1)
        <div class="animate-fade-in-up">
            <h2 class="mb-1 text-lg font-bold">{{ __('booking.service.title') }}</h2>
            <p class="mb-5 text-sm text-content/40">{{ __('booking.service.subtitle') }}</p>
            @error('serviceId')
                <div class="mb-4 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm font-medium text-danger">{{ $message }}</div>
            @enderror
            <div class="space-y-2.5" wire:loading.class="pointer-events-none opacity-50" wire:target="selectService">
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
                    <div class="rounded-2xl border border-content/[0.06] bg-content/[0.03] p-8 text-center text-sm text-content-subtle">
                        {{ __('booking.service.empty') }}
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- STEP 2: Barbers --}}
    @if ($step === 2)
        <div class="animate-fade-in-up">
            <button type="button" wire:click="back" class="mb-4 flex items-center gap-1 text-xs text-content-subtle transition hover:text-content/60">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                {{ __('booking.back') }}
            </button>
            <h2 class="mb-1 text-lg font-bold">{{ __('booking.barber.title') }}</h2>
            <p class="mb-5 text-sm text-content/40">{{ __('booking.barber.subtitle') }}</p>
            <div class="grid grid-cols-2 gap-3" wire:loading.class="pointer-events-none opacity-50" wire:target="selectBarber">
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
                        @php($barberPrice = $barber->priceForService($serviceId))
                        @if ($barberPrice)
                            <div class="mt-1 text-xs font-bold text-brass-ink">{{ number_format($barberPrice, 0, '.', ' ') }} {{ __('common.currency') }}</div>
                        @endif
                    </button>
                @empty
                    <div class="col-span-2 rounded-2xl border border-content/[0.06] bg-content/[0.03] p-8 text-center text-sm text-content-subtle">
                        {{ __('booking.barber.empty') }}
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- STEP 3: Date & Time --}}
    @if ($step === 3)
        <div class="animate-fade-in-up">
            <button type="button" wire:click="back" class="mb-4 flex items-center gap-1 text-xs text-content-subtle transition hover:text-content/60">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                {{ __('booking.back') }}
            </button>
            <h2 class="mb-1 text-lg font-bold">{{ __('booking.datetime.title') }}</h2>
            <p class="mb-5 text-sm text-content/40">{{ __('booking.datetime.subtitle') }}</p>
            @error('time')
                <div class="mb-4 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm font-medium text-danger">{{ $message }}</div>
            @enderror

            {{-- Horizontal date picker --}}
            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-content/40">{{ $this->selectedDate->translatedFormat('F Y') }}</div>
            <div class="hide-scrollbar -mx-4 mb-2 flex gap-2 overflow-x-auto px-4 pb-1">
                @for ($i = 0; $i < $this->dateHorizonDays; $i++)
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
            <p class="mb-5 text-[11px] text-content/25">{{ __('booking.datetime.horizon') }}</p>

            {{-- Time slots --}}
            @php($takenSlots = $this->takenSlots)
            @php($freeSlots = array_diff(array_column($this->availableSlots, 'value'), $takenSlots))
            @if (count($freeSlots) === 0)
                <div class="rounded-2xl border border-brass/10 bg-brass/5 p-5 text-center text-sm text-brass-ink/60">
                    {{ __('booking.datetime.no_slots') }}
                </div>
            @else
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-4" wire:loading.class="pointer-events-none animate-pulse" wire:target="date">
                    @foreach ($this->availableSlots as $slot)
                        @php($isTaken = in_array($slot['value'], $takenSlots, true))
                        <button type="button" wire:key="bk-slot-{{ $slot['value'] }}"
                                wire:click="selectTime('{{ $slot['value'] }}')"
                                @disabled($isTaken)
                                @if ($isTaken)
                                    title="{{ __('booking.datetime.taken') }}"
                                    aria-label="{{ $slot['label'] }} — {{ __('booking.datetime.taken') }}"
                                @endif
                                @class([
                                    'flex flex-col items-center rounded-xl border px-3 py-2.5 text-sm font-semibold transition-all duration-200',
                                    'cursor-not-allowed border-danger/30 bg-danger/10 text-danger/60 line-through' => $isTaken,
                                    'border-content/[0.06] bg-content/[0.03] hover:border-brass/40 hover:bg-brass/10 hover:text-brass-ink active:scale-95' => ! $isTaken,
                                ])>
                            {{ $slot['label'] }}
                            @if ($isTaken)
                                <span class="text-[9px] font-medium uppercase tracking-wide no-underline">{{ __('booking.datetime.taken_short') }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- STEP 4: Contacts & Confirm --}}
    @if ($step === 4)
        <div class="animate-fade-in-up">
            <button type="button" wire:click="back" class="mb-4 flex items-center gap-1 text-xs text-content-subtle transition hover:text-content/60">
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
                    @if ($this->quotedPrice)
                        <div class="text-sm font-bold text-brass-ink">{{ $this->formattedQuotedPrice }}</div>
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
                        <div class="text-sm font-semibold">{{ $this->selectedDate->translatedFormat('d M') }}</div>
                        <div class="text-xs text-brass-ink">{{ $time }}</div>
                    </div>
                </div>
            </div>

            {{-- Contact form --}}
            @php($ready = $this->phoneReady)
            <form wire:submit.prevent="confirm" class="space-y-3">
                {{-- Phone comes first: it unlocks the fields below --}}
                <div>
                    <label for="phone" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('booking.confirm.phone') }}</label>
                    <div class="relative">
                        <input id="phone" type="tel" inputmode="tel" autocomplete="tel" autofocus wire:model.live.debounce.400ms="phone" placeholder="998 90 123 45 67"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 pr-10 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        <div wire:loading.delay wire:target="phone" class="absolute inset-y-0 right-3.5 flex items-center">
                            <svg class="h-4 w-4 animate-spin text-content-subtle" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        </div>
                        @if ($ready && $this->clientFound)
                            <div wire:loading.remove wire:target="phone" class="absolute inset-y-0 right-3.5 flex items-center">
                                <svg class="h-4 w-4 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            </div>
                        @endif
                    </div>
                    @error('phone') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    @if ($ready && $this->clientFound)
                        <p class="mt-1.5 text-xs text-success">{{ __('booking.confirm.client_found') }}</p>
                    @endif
                </div>

                {{-- Name + birth date: locked until a valid phone is entered --}}
                <div @class(['space-y-3 transition-opacity duration-300', 'pointer-events-none opacity-40' => ! $ready])>
                    @unless ($ready)
                        <p class="flex items-center gap-1.5 text-xs text-content/35">
                            <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                            {{ __('booking.confirm.phone_hint') }}
                        </p>
                    @endunless
                    <div>
                        <label for="name" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('booking.confirm.name') }}</label>
                        <input id="name" type="text" wire:model="name" placeholder="{{ __('booking.confirm.name_placeholder') }}" @disabled(! $ready)
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 disabled:cursor-not-allowed">
                        @error('name') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="birth_date" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('booking.confirm.birth_date') }} <span class="font-normal text-content/25">{{ __('booking.confirm.optional') }}</span></label>
                        <input id="birth_date" type="date" wire:model="birth_date" @disabled(! $ready)
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 disabled:cursor-not-allowed dark:[color-scheme:dark]">
                        @error('birth_date') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>

                <button type="submit" @disabled(! $ready)
                        class="mt-1 w-full rounded-xl bg-gradient-to-r from-brass to-brass px-4 py-3.5 text-sm font-bold text-black shadow-lg shadow-brass/20 transition-all hover:shadow-brass/30 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50"
                        wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="confirm">{{ __('booking.confirm.submit') }}</span>
                    <span wire:loading wire:target="confirm">{{ __('booking.confirm.submitting') }}</span>
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
                    'date' => $this->selectedDate->translatedFormat('d M'),
                    'time' => $time,
                    'barber' => $this->selectedBarber?->name,
                ]) }}
            </p>
            <p class="mt-1.5 text-xs text-content/25">{{ __('booking.success.sms_note') }}</p>

            <div class="mx-auto mt-6 max-w-xs rounded-2xl border border-content/[0.06] bg-content/[0.03] p-4 text-left text-sm">
                @if ($confirmedAppointmentId)
                    <div class="flex items-center justify-between border-b border-content/[0.06] pb-2">
                        <span class="text-content/40">{{ __('booking.success.booking_number') }}</span>
                        <span class="font-mono font-semibold">#{{ $confirmedAppointmentId }}</span>
                    </div>
                @endif
                <div class="flex items-center justify-between {{ $confirmedAppointmentId ? 'mt-2' : '' }}">
                    <span class="text-content/40">{{ __('booking.success.service') }}</span>
                    <span class="font-medium">{{ $this->selectedService?->name }}</span>
                </div>
                <div class="mt-2 flex items-center justify-between">
                    <span class="text-content/40">{{ __('booking.success.price') }}</span>
                    <span class="font-semibold text-brass-ink">{{ $this->formattedQuotedPrice ?: '—' }}</span>
                </div>
            </div>

            {{-- Salon contacts (#76): the client is promised a call-back and had no
                 way to reach the salon in the meantime. --}}
            @if ($this->shopPhone || $this->shopAddress || $this->shopTelegramUrl)
                <div class="mx-auto mt-4 max-w-xs rounded-2xl border border-content/[0.06] bg-content/[0.03] p-4 text-left text-sm">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-content/40">{{ __('booking.success.contacts_title') }}</div>
                    <div class="space-y-1.5">
                        @if ($this->shopPhone)
                            <a href="tel:{{ preg_replace('/\D+/', '', $this->shopPhone) }}" class="flex items-center gap-2 text-content/70 transition hover:text-brass-ink">
                                <svg class="h-4 w-4 shrink-0 text-content-subtle" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                                {{ $this->shopPhone }}
                            </a>
                        @endif
                        @if ($this->shopAddress)
                            <div class="flex items-center gap-2 text-content/70">
                                <svg class="h-4 w-4 shrink-0 text-content-subtle" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                                {{ $this->shopAddress }}
                            </div>
                        @endif
                        @if ($this->shopTelegramUrl)
                            <a href="{{ $this->shopTelegramUrl }}" target="_blank" rel="noopener" class="flex items-center gap-2 text-content/70 transition hover:text-brass-ink">
                                <svg class="h-4 w-4 shrink-0 text-content-subtle" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg>
                                {{ __('booking.success.open_bot') }}
                            </a>
                        @endif
                    </div>
                </div>
            @endif

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
