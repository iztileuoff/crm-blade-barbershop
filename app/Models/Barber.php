<?php

namespace App\Models;

use Database\Factories\BarberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Barber extends Model implements HasMedia
{
    /** @use HasFactory<BarberFactory> */
    use HasFactory, InteractsWithMedia;

    /**
     * Canonical weekday keys the `schedule` column and its admin form key by,
     * Monday first to match {@see Carbon::dayOfWeekIso()}
     * (1 = Monday .. 7 = Sunday).
     *
     * @var array<int, string>
     */
    public const array WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected $fillable = [
        'user_id',
        'name',
        'specialization_id',
        'price',
        'salary_percent',
        'schedule',
        'is_active',
    ];

    /**
     * `schedule` shape (#73), per weekday key from {@see WEEKDAYS}:
     * `{start: 'H:i', end: 'H:i', off: false}` for a working day, or
     * `{start: null, end: null, off: true}` for a day off. A day missing
     * from the array — or the whole column being `null` — means the barber
     * has no schedule for that day at all. Rows saved before #73 may still
     * hold the old positional shape, a plain `[start, end]` pair with no
     * `off` concept. {@see scheduleWindowForDay()} is the one place that
     * needs to know about both shapes; everywhere else should read through it.
     *
     * @var array<string, array{start: ?string, end: ?string, off: bool}>|null
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'salary_percent' => 'integer',
            'schedule' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')->singleFile();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function specialization(): BelongsTo
    {
        return $this->belongsTo(Specialization::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)->withPivot('price');
    }

    public function priceForService(?int $serviceId): ?int
    {
        if (! $serviceId) {
            return $this->price;
        }

        /** @var Service|null $pivot */
        $pivot = $this->services->firstWhere('id', $serviceId);

        return $pivot?->pivot->price ?? $this->price;
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * This barber's own working window for one weekday, normalised from
     * whatever `schedule` holds for it — see the shape documented above
     * {@see casts()}. Callers narrow the salon's own hours with this; they
     * must not read `schedule` directly.
     *
     * @param  string  $day  One of {@see WEEKDAYS}.
     * @return array{start: string, end: string}|'off'|null `null` means the
     *                                                      caller should fall back to the salon's own hours (no schedule for
     *                                                      this day, including every row saved before #73 that never touched
     *                                                      it); `'off'` means no slots at all that day; the array gives the
     *                                                      explicit window.
     */
    public function scheduleWindowForDay(string $day): array|string|null
    {
        $entry = is_array($this->schedule) ? ($this->schedule[$day] ?? null) : null;

        if (! is_array($entry)) {
            return null;
        }

        // Shape saved by the #73 form: keyed by start/end/off.
        if (array_key_exists('off', $entry) || array_key_exists('start', $entry)) {
            if (! empty($entry['off'])) {
                return 'off';
            }

            return $this->validTimeWindow($entry['start'] ?? null, $entry['end'] ?? null);
        }

        // Позиционная пара [start, end] осталась от формы, которая сохраняла
        // расписание, но никто его не читал: такие значения есть почти у каждого
        // мастера, и они никогда не влияли на слоты. Начать их соблюдать сейчас —
        // значит на деплое молча сузить часы всем сразу. Считаем их отсутствием
        // расписания: график становится живым только после явного пересохранения
        // в новой форме, где он показан как есть {@see scheduleForForm()}.
        return null;
    }

    /**
     * То же расписание, но для формы редактирования: здесь позиционная пара
     * из старых данных читается — админ должен видеть, что было введено, и
     * подтвердить это осознанно.
     *
     * @return array{start: string, end: string}|'off'|null
     */
    public function scheduleForForm(string $day): array|string|null
    {
        $window = $this->scheduleWindowForDay($day);

        if ($window !== null) {
            return $window;
        }

        $entry = is_array($this->schedule) ? ($this->schedule[$day] ?? null) : null;

        return is_array($entry)
            ? $this->validTimeWindow($entry[0] ?? null, $entry[1] ?? null)
            : null;
    }

    /**
     * @return array{start: string, end: string}|null
     */
    private function validTimeWindow(mixed $start, mixed $end): ?array
    {
        if (! is_string($start) || ! is_string($end)) {
            return null;
        }

        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)) {
            return null;
        }

        return $end > $start ? ['start' => $start, 'end' => $end] : null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public string $photoUrl {
        get => $this->getFirstMediaUrl('photo') ?: '';
    }

    /**
     * Цена в формате "12 000 сум" (PHP 8.4 property hook).
     */
    public string $formattedPrice {
        get => number_format((int) $this->price, 0, '.', ' ').' '.__('common.currency');
    }
}
