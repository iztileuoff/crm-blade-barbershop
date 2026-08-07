<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use App\Support\PaymentSplit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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

    /**
     * Потолок для визита, переходящего через полночь.
     *
     * Конец, который по часам не позже начала, читается как следующий день —
     * 23:00→00:00 это час, а не «минус 23 часа» (issue #99). Но то же правило
     * превращает опечатку 23:00→22:00 в 23-часовой визит, который вычеркнет
     * мастеру весь следующий день, поэтому переход ограничен. Самая долгая
     * услуга в каталоге — 3 часа, так что шесть не мешают ни одной реальной
     * записи. Дневной визит эта граница не трогает: он и так не переходит
     * через полночь.
     */
    private const MAX_OVERNIGHT_HOURS = 6;

    public ?int $editingId = null;

    public ?int $client_id = null;

    #[Validate('required|exists:barbers,id')]
    public ?int $barber_id = null;

    /** @var array<int, array{service_id: int|null, amount: int|null}> */
    public array $selectedServices = [];

    #[Validate('nullable|integer|min:0')]
    public ?int $price = null;

    #[Validate('nullable|string|max:500')]
    public string $note = '';

    #[Validate('required|in:cash,card,both')]
    public string $payment_type = 'cash';

    #[Validate('nullable|integer|min:0')]
    public ?int $cash_amount = null;

    #[Validate('nullable|integer|min:0')]
    public ?int $card_amount = null;

    #[Validate('nullable|integer|min:0')]
    public ?int $debt_amount = null;

    public bool $debtEnabled = false;

    /**
     * Уже принятая по долгу сумма — только для подсказки в форме.
     * Заперта: при сохранении значение всё равно перечитывается из БД.
     */
    #[Locked]
    public int $debtAlreadyPaid = 0;

    #[Validate('required|date')]
    public string $form_date = '';

    #[Validate('required|regex:/^\d{2}:\d{2}$/')]
    public string $form_start_time = '';

    #[Validate('required|regex:/^\d{2}:\d{2}$/')]
    public string $form_end_time = '';

    /**
     * Заперто: форму открывают только серверные действия. Незапертым свойством
     * мастер открывал модалку и вычитывал через неё справочник клиентов.
     */
    #[Locked]
    public bool $showForm = false;

    public int $timeStep = 60;

    public string $clientSearch = '';

    /**
     * Роль и «свой» мастер — заперты: публичные свойства Livewire пишутся с
     * клиента, и без замка мастер мог одним запросом выставить isBarberView
     * в false и дотянуться до чужих записей.
     */
    #[Locked]
    public bool $isBarberView = false;

    #[Locked]
    public ?int $ownBarberId = null;

    /**
     * Баннер «раздел недоступен» — одноразовый: гасится из session()->pull()
     * в mount() (флаг ставит RestrictBarberAccess при редиректе) и дальше
     * живёт только в свойстве компонента, а не перечитывается из сессии.
     */
    public bool $showBarberRestrictedBanner = false;

    public function mount(): void
    {
        // ?date= приходит с карточек дашборда («записей на этот день») — тот же
        // клиентский ввод, что и у date-пикера ниже, поэтому валидируется тем
        // же правилом, а не читается напрямую.
        $this->date = $this->validDateOrToday(request()->query('date'));
        $this->form_date = $this->date;

        // pull(), а не get(): баннер должен показаться ровно один раз, даже
        // если этот же ответ ещё будет перечитан (например при F5 до дисмисса).
        $this->showBarberRestrictedBanner = (bool) session()->pull('barberRestricted', false);

        $user = auth()->user();

        if ($user?->isBarber()) {
            $this->isBarberView = true;
            $this->ownBarberId = $user->barber?->id;
            $this->barberFilter = $this->ownBarberId;

            return;
        }

        // Переход из карточки клиента («записать на визит»): форма открывается
        // с уже привязанным клиентом, искать его заново не нужно.
        $client = Client::find(request()->integer('client'));

        if ($client !== null) {
            $this->client_id = $client->id;
            $this->clientSearch = $client->name;
            $this->showForm = true;
        }
    }

    public function dismissBarberRestrictedBanner(): void
    {
        $this->showBarberRestrictedBanner = false;
    }

    /**
     * Роль берём из сессии, а не из свойства компонента: свойство — лишь
     * подсказка для шаблона, источником прав оно быть не может.
     */
    private function abortIfBarber(): void
    {
        abort_if(auth()->user()?->isBarber() ?? false, 403);
    }

    /**
     * Начало выбранного дня. $date — клиентское поле, поэтому разбор защищён:
     * иначе кривое значение даёт 500 вместо пустого списка.
     */
    private function dayStart(): Carbon
    {
        try {
            return Carbon::parse($this->date, 'Asia/Tashkent')->startOfDay();
        } catch (Exception) {
            return Carbon::now('Asia/Tashkent')->startOfDay();
        }
    }

    /**
     * Валидная дата вида YYYY-MM-DD или сегодня. Общее правило для mount()
     * (?date= из ссылки) и updatedDate() (пикер): оба источника клиентские,
     * и кривое значение не должно молча укладываться в 1969-й или ронять день.
     */
    private function validDateOrToday(mixed $value): string
    {
        if (
            is_string($value) &&
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1 &&
            checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
        ) {
            return $value;
        }

        return Carbon::now('Asia/Tashkent')->toDateString();
    }

    /**
     * Id мастера, если страницу смотрит мастер. Тоже из сессии.
     */
    private function sessionBarberId(): ?int
    {
        $user = auth()->user();

        return $user?->isBarber() ? $user->barber?->id : null;
    }

    /**
     * Мастер, у которого роль есть, а профиля (barbers.id) нет. Раньше в этом
     * случае фильтры по мастеру просто не срабатывали, и такой мастер видел
     * весь день всех мастеров — та же категория утечки, что и #38.
     */
    #[Computed]
    public function isUnlinkedBarber(): bool
    {
        $user = auth()->user();

        return ($user?->isBarber() ?? false) && $user->barber === null;
    }

    /**
     * Смена статуса — единственное действие, доступное мастеру, и только над
     * своими записями. Не-мастеру (админу) ограничений здесь нет: деньги,
     * удаление и остальной CRUD по-прежнему закрыты abortIfBarber() в своих
     * методах. Ownership проверяется от id из сессии, а не от свойства
     * компонента — его мастер мог бы переписать запросом.
     */
    private function findForStatusChange(int $id): Appointment
    {
        if (! (auth()->user()?->isBarber() ?? false)) {
            return Appointment::findOrFail($id);
        }

        $barberId = $this->sessionBarberId();

        // Мастеру отвечаем одинаково и на чужую запись, и на несуществующую:
        // иначе разница между 403 и 404 сама по себе выдаёт, какие id заняты,
        // и перебором вычитывается вся книга записей салона.
        $appointment = $barberId === null
            ? null
            : Appointment::query()->whereKey($id)->where('barber_id', $barberId)->first();

        abort_if($appointment === null, 403);

        return $appointment;
    }

    /**
     * Перевод статуса. Повторный перевод в тот же статус — не ошибка, но и не
     * повод для работы: у отмены на observer'е висит уведомление клиенту, и без
     * этой проверки мастер мог бы слать его сколько угодно раз подряд.
     */
    private function changeStatus(int $id, AppointmentStatus $status): void
    {
        $appointment = $this->findForStatusChange($id);

        if ($appointment->status === $status) {
            return;
        }

        $appointment->update(['status' => $status]);
        unset($this->appointments);
    }

    /**
     * Признак «на экране сегодня» — подсветка кнопки «Сегодня» в дате-строке.
     */
    #[Computed]
    public function isToday(): bool
    {
        return $this->date === Carbon::now('Asia/Tashkent')->toDateString();
    }

    #[Computed]
    public function filteredClients()
    {
        // Ограничиваем само чтение, а не только флаг формы: мастеру справочник
        // клиентов не положен, ему закрыт и весь раздел «Клиенты».
        if ($this->sessionBarberId() !== null) {
            return collect();
        }

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

    /**
     * Подтверждённый выбор клиента — тот, на кого реально уедет визит.
     */
    #[Computed]
    public function selectedClient(): ?Client
    {
        return $this->client_id ? Client::find($this->client_id) : null;
    }

    /**
     * Выбор клиента из выпадашки. Компонент передаёт только id: подпись
     * строится здесь, из самой записи, — раньше она приезжала с клиента вместе
     * с id, и текст в поле мог описывать не того, кого выбрали.
     *
     * Мастеру справочник клиентов не положен — ему и filteredClients отдаёт
     * пустую коллекцию; повторяем отсечку здесь, чтобы метод не превратился
     * в проверку «существует ли такой id».
     */
    public function selectClient(int $id): void
    {
        if ($this->sessionBarberId() !== null) {
            return;
        }

        $client = Client::find($id);

        if ($client === null) {
            return;
        }

        $this->client_id = $client->id;
        $this->clientSearch = $client->phone ? "{$client->name} ({$client->phone})" : $client->name;
    }

    /**
     * Текст поиска правят — значит, выбранный id больше не подтверждён.
     * Иначе визит с деньгами, долгом и SMS уедет на клиента, которого в поле
     * уже не видно.
     */
    public function updatedClientSearch(): void
    {
        $this->client_id = null;
    }

    public function clearClient(): void
    {
        $this->client_id = null;
        $this->clientSearch = '';
    }

    #[Computed]
    public function appointments()
    {
        // Мастер без привязанного профиля (barber_id = null) не должен видеть
        // ничьи записи: без этой отсечки $ownBarberId ниже — null, и запрос
        // проваливается во вторую ветку фильтра (по $barberFilter), который у
        // мастера по умолчанию пуст — получаем чужой день целиком.
        if ($this->isUnlinkedBarber) {
            return collect();
        }

        // Мастеру отдаём только его записи, и решает это сессия, а не свойство.
        $ownBarberId = $this->sessionBarberId();
        $day = $this->dayStart();

        return Appointment::query()
            ->with(['client', 'barber', 'services'])
            ->withSum('debtPayments as debt_paid_total', 'amount')
            // Полуоткрытый диапазон вместо whereDate: date(starts_at) = ? не
            // ложится на индекс (starts_at, notified_30min).
            ->whereBetween('starts_at', [$day, $day->copy()->endOfDay()])
            ->when($ownBarberId !== null, fn ($q) => $q->where('barber_id', $ownBarberId))
            ->when($ownBarberId === null && $this->barberFilter, fn ($q) => $q->where('barber_id', $this->barberFilter))
            ->orderBy($this->sortableField(), $this->sortDirection === 'desc' ? 'desc' : 'asc')
            ->get();
    }

    /**
     * Сводка дня по уже загруженной коллекции $this->appointments — без
     * нового запроса. Отменённые записи не в счёт: они не бронируют время и
     * не должны раздувать сумму дня, которая нужна для быстрой прикидки, а не
     * для кассы (та считается на дашборде по другим правилам).
     *
     * @return array{count: int, total: int, debt: int}
     */
    #[Computed]
    public function daySummary(): array
    {
        $active = $this->appointments->reject(fn (Appointment $a) => $a->status === AppointmentStatus::Cancelled);

        return [
            'count' => $this->appointments->count(),
            'total' => (int) $active->sum(fn (Appointment $a) => (int) ($a->price ?? 0)),
            'debt' => (int) $active->sum(fn (Appointment $a) => $a->outstandingDebt),
        ];
    }

    /**
     * Колонка сортировки только из белого списка: поле приходит с клиента, и
     * произвольное имя роняло бы запрос.
     */
    private function sortableField(): string
    {
        return in_array($this->sortField, ['starts_at', 'status', 'price', 'barber_id'], true)
            ? $this->sortField
            : 'starts_at';
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
        return Service::active()->ordered()->get();
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

        // Сетка строится по $timeStep, а выбранное время может ему не кратно
        // (например 45-минутная услуга даёт 10:45 при шаге в час) — без этого
        // <select> рендерит пустое значение и форма падает в необъяснимую ошибку.
        foreach ([$this->form_start_time, $this->form_end_time] as $value) {
            if (preg_match('/^\d{2}:\d{2}$/', $value) && ! in_array($value, $slots, true)) {
                $slots[] = $value;
            }
        }

        sort($slots);

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

    /**
     * $date приходит из <input type="date">: поле может быть очищено или
     * содержать синтаксически похожую, но невозможную дату вроде
     * «0000-00-00» — Carbon её разберёт без ошибки и утянет страницу в
     * 1969-й или в отрицательный год. checkdate() отсекает и то, и другое.
     */
    public function updatedDate($value): void
    {
        $this->date = $this->validDateOrToday($value);
        $this->form_date = $this->date;
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
        $this->abortIfBarber();
        $this->resetForm();

        // Мастер под активным фильтром — вероятная цель новой записи, но
        // только если это реальный мастер из списка, который можно выбрать
        // в самой форме, а не устаревший или чужой id в фильтре.
        if ($this->barberFilter && $this->barbers->contains('id', $this->barberFilter)) {
            $this->barber_id = $this->barberFilter;
        }

        $this->form_start_time = $this->suggestedStartTime();
        $this->showForm = true;
    }

    /**
     * Разумное время начала для новой записи: если открываемый день —
     * сегодня, это ближайшая наступившая граница слота, иначе первый слот
     * дня. Округление — по активному $timeStep, часовой пояс — как в
     * dayStart().
     */
    private function suggestedStartTime(): string
    {
        $step = in_array($this->timeStep, [15, 30, 60], true) ? $this->timeStep : 60;
        $now = Carbon::now('Asia/Tashkent');

        if (! $this->dayStart()->isSameDay($now)) {
            return '00:00';
        }

        $minutesSinceMidnight = min($now->hour * 60 + $now->minute, 23 * 60);
        $rounded = min((int) (ceil($minutesSinceMidnight / $step) * $step), 23 * 60);

        return Carbon::createFromTime(0, 0)->addMinutes($rounded)->format('H:i');
    }

    public function edit(int $id): void
    {
        $this->abortIfBarber();
        $appointment = Appointment::with(['services', 'client'])->findOrFail($id);
        $this->editingId = $appointment->id;
        $this->client_id = $appointment->client_id;
        $this->clientSearch = $appointment->client?->name ?? '';
        $this->barber_id = $appointment->barber_id;
        $this->note = (string) $appointment->note;
        $this->payment_type = $appointment->payment_type->value;
        $this->cash_amount = $appointment->cash_amount;
        $this->card_amount = $appointment->card_amount;
        $this->debt_amount = $appointment->debt_amount;
        $this->debtEnabled = ($appointment->debt_amount ?? 0) > 0;
        // Сколько по этому долгу уже принято — ниже этой суммы опускать нельзя.
        $this->debtAlreadyPaid = $appointment->debtPaid;
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

            // Только по первой услуге (от неё считается конец) и только пока
            // конец не заполнен вручную — иначе правка второй услуги затирала
            // бы уже выставленное админом время.
            if ($index === 0 || trim((string) $this->form_end_time) === '') {
                $this->deriveEndTime();
            }
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

    public function updatedDebtEnabled($value): void
    {
        if (! $value) {
            $this->debt_amount = null;
            $this->resetErrorBag('debt_amount');
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
        $this->deriveEndTime();
    }

    /**
     * Конец визита = начало + длительность первой услуги.
     *
     * Считается и на смену времени, и на смену первой услуги. Второе
     * обязательно: openCreate() подставляет начало присваиванием, а от
     * присваивания хук не срабатывает, — значит выбор услуги остаётся
     * единственным моментом, когда конец ещё можно вывести. Без этого форма
     * доходила до save() с пустым концом и падала на «конец должен быть позже
     * начала» — на поле, к которому админ не прикасался.
     */
    private function deriveEndTime(): void
    {
        if (! preg_match('/^\d{2}:\d{2}$/', (string) $this->form_start_time)) {
            return;
        }

        $firstServiceId = $this->selectedServices[0]['service_id'] ?? null;

        if (! $firstServiceId) {
            return;
        }

        $service = Service::find($firstServiceId);

        if ($service) {
            $this->form_end_time = Carbon::createFromFormat('H:i', $this->form_start_time)
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

        if ($this->resolveEndsAt($startsAt, $value) === null) {
            $this->addError('form_end_time', __('appointments.err_overnight_too_long', ['hours' => self::MAX_OVERNIGHT_HOURS]));
        } else {
            $this->resetErrorBag('form_end_time');
        }
    }

    /**
     * Конец визита датой и временем — единственное место, где решается, на какой
     * день он попадает.
     *
     * Конец, который по часам не позже начала, означает переход через полночь:
     * стрижка в 23:00 заканчивается в 00:00 следующего дня, а не «раньше, чем
     * началась» (issue #99). Такой переход и раньше был возможен — deriveEndTime()
     * сама выводила 00:00 для часовой услуги в 23:00, — но save() его отбивал, и
     * админ упирался в ошибку на поле, которое форма заполнила за него.
     *
     * `null` означает, что переход длиннее {@see MAX_OVERNIGHT_HOURS}: это уже не
     * ночной визит, а опечатка во времени.
     */
    private function resolveEndsAt(Carbon $startsAt, string $endTime): ?Carbon
    {
        $endsAt = $startsAt->copy()->setTimeFromTimeString($endTime);

        if ($endsAt->gt($startsAt)) {
            return $endsAt;
        }

        $endsAt->addDay();

        return $startsAt->diffInMinutes($endsAt, absolute: true) <= self::MAX_OVERNIGHT_HOURS * 60
            ? $endsAt
            : null;
    }

    /**
     * Метод объявлен отдельно, чтобы validate() без аргументов не терял
     * правила #[Validate].
     *
     * Клиент обязателен всегда, а не только под долг: `appointments.client_id`
     * объявлен NOT NULL с самой первой миграции, и «необязательный» клиент
     * доводил форму не до ошибки поля, а до падения вставки. К клиенту, кроме
     * того, привязано всё, что происходит после визита, — карточка, долг,
     * уведомление об отмене, SMS-удержание.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            // Суммы услуг складываются в price — отрицательная строка иначе
            // молча уменьшала бы цену визита.
            'selectedServices.*.service_id' => 'nullable|integer|exists:services,id',
            'selectedServices.*.amount' => 'nullable|integer|min:0',
        ];
    }

    /**
     * Под полем клиента должно стоять человеческое предложение, а не
     * «поле client id обязательно».
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.required' => __('appointments.err_client_required'),
        ];
    }

    public function save(): void
    {
        $this->abortIfBarber();
        $this->validate();

        $validServices = collect($this->selectedServices)
            ->filter(fn ($row) => ! empty($row['service_id']))
            ->values();

        if ($validServices->isEmpty()) {
            $this->addError('selectedServices', __('appointments.err_add_service'));

            return;
        }

        $uniqueIds = $validServices->pluck('service_id')->map(fn ($id) => (int) $id)->unique();

        if ($uniqueIds->count() !== $validServices->count()) {
            $this->addError('selectedServices', __('appointments.err_service_once'));

            return;
        }

        $startsAt = Carbon::parse($this->form_date.' '.$this->form_start_time);
        $endsAt = $this->resolveEndsAt($startsAt, $this->form_end_time);

        if ($endsAt === null) {
            $this->addError('form_end_time', __('appointments.err_overnight_too_long', ['hours' => self::MAX_OVERNIGHT_HOURS]));

            return;
        }

        $this->recalculateTotal();

        $payloadBase = [
            'client_id' => $this->client_id,
            'barber_id' => $this->barber_id,
            'price' => $this->price,
            'note' => $this->note ?: null,
            'payment_type' => $this->payment_type,
            'cash_amount' => $this->payment_type === 'both' ? $this->cash_amount : null,
            'card_amount' => $this->payment_type === 'both' ? $this->card_amount : null,
            'debt_amount' => ($this->debt_amount ?? 0) > 0 ? $this->debt_amount : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];

        $syncData = $validServices->mapWithKeys(fn ($row) => [
            (int) $row['service_id'] => ['amount' => (int) ($row['amount'] ?? 0)],
        ])->toArray();

        $moneyErrors = [];

        // Одна транзакция на всё: погашенную сумму читаем под блокировкой (иначе
        // параллельное погашение позволит опустить долг ниже принятого), а цена
        // и услуги, из которых она посчитана, пишутся вместе.
        $appointment = DB::transaction(function () use (&$moneyErrors, $payloadBase, $syncData) {
            $existing = $this->editingId
                ? Appointment::query()->lockForUpdate()->find($this->editingId)
                : null;

            abort_if($this->editingId !== null && $existing === null, 404);

            $moneyErrors = PaymentSplit::errors(
                $this->payment_type,
                (int) ($this->price ?? 0),
                $this->cash_amount,
                $this->card_amount,
                $this->debt_amount,
                $existing?->debtPaid ?? 0,
            );

            if ($moneyErrors !== []) {
                return null;
            }

            $payload = $payloadBase + [
                'status' => $existing?->status ?? AppointmentStatus::Confirmed,
            ];

            if ($existing !== null) {
                $existing->update($payload);
                $appointment = $existing;
            } else {
                $appointment = Appointment::create($payload);
            }

            $appointment->services()->sync($syncData);

            return $appointment;
        });

        if ($appointment === null) {
            foreach ($moneyErrors as $field => $message) {
                $this->addError($field, $message);
            }

            return;
        }

        unset($this->appointments);
        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('saved');
    }

    public function markConfirmed(int $id): void
    {
        $this->changeStatus($id, AppointmentStatus::Confirmed);
    }

    public function markCompleted(int $id): void
    {
        $this->changeStatus($id, AppointmentStatus::Completed);
    }

    public function markCancelled(int $id): void
    {
        $this->changeStatus($id, AppointmentStatus::Cancelled);
    }

    public function delete(int $id): void
    {
        $this->abortIfBarber();
        $this->resetErrorBag('delete');

        $appointment = Appointment::findOrFail($id);

        // Удаление унесёт и погашения — а они лежат в кассе других дней.
        // Ошибка идёт в ключ `delete`: его баннер живёт над таблицей, а не
        // внутри модалки, закрытой при удалении из строки.
        if ($appointment->debtPayments()->exists()) {
            $this->addError('delete', __('appointments.err_delete_has_payments'));

            return;
        }

        $appointment->delete();
        unset($this->appointments);
    }

    public function dismissDeleteError(): void
    {
        $this->resetErrorBag('delete');
    }

    /**
     * Есть что терять при закрытии формы: выбранный клиент, добавленная
     * услуга, ненулевая цена или текст заметки. Пустую форму закрывают
     * без вопросов, непустую — только через подтверждение.
     */
    #[Computed]
    public function formHasContent(): bool
    {
        if ($this->client_id !== null) {
            return true;
        }

        $hasService = collect($this->selectedServices)
            ->contains(fn ($row) => ! empty($row['service_id']));

        if ($hasService || ($this->price ?? 0) > 0) {
            return true;
        }

        return trim($this->note) !== '';
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'client_id', 'barber_id', 'selectedServices', 'price', 'note', 'form_start_time', 'form_end_time', 'cash_amount', 'card_amount', 'debt_amount', 'debtEnabled', 'debtAlreadyPaid']);
        $this->payment_type = 'cash';
        $this->form_date = $this->date;
        $this->resetErrorBag();
    }
}; ?>

<x-slot:title>{{ __('appointments.page_title') }}</x-slot:title>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('appointments.title') }}</h1>
            <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-content/40">
                <span>{{ __('appointments.subtitle') }}</span>
                {{-- Сводка дня — по уже загруженной $this->appointments, без нового запроса. --}}
                <span class="text-content/25" aria-hidden="true">·</span>
                <span>{{ __('appointments.day_summary_count', ['count' => $this->daySummary['count']]) }}</span>
                @if ($this->daySummary['total'] > 0)
                    <span class="text-content/25" aria-hidden="true">·</span>
                    <span>{{ __('appointments.day_summary_total', ['amount' => number_format($this->daySummary['total'], 0, '.', ' ').' '.__('common.currency')]) }}</span>
                @endif
                @if ($this->daySummary['debt'] > 0)
                    <span class="text-content/25" aria-hidden="true">·</span>
                    <span class="text-danger/60">{{ __('appointments.day_summary_debt', ['amount' => number_format($this->daySummary['debt'], 0, '.', ' ').' '.__('common.currency')]) }}</span>
                @endif
            </p>
        </div>
        @unless ($isBarberView)
            <div class="flex items-center gap-3">
                <div class="relative">
                    <select wire:model.live="barberFilter"
                            aria-label="{{ __('common.barber') }}"
                            class="appearance-none rounded-xl border border-content/[0.08] bg-surface-sunken py-2.5 pl-4 pr-10 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 focus-visible:ring-2 focus-visible:ring-brass/40 [&>option]:bg-surface-raised">
                        <option value="">{{ __('appointments.all_barbers') }}</option>
                        @foreach ($this->barbers as $barber)
                            <option value="{{ $barber->id }}">{{ $barber->name }}</option>
                        @endforeach
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-content-subtle">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                    </div>
                </div>
                <button type="button" wire:click="openCreate"
                        class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-brass to-brass px-5 py-2.5 text-sm font-bold text-black shadow-lg shadow-brass/20 transition-all hover:scale-[1.02] hover:shadow-brass/30 active:scale-[0.98]">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    {{ __('appointments.new') }}
                </button>
            </div>
        @endunless
    </div>

    {{-- Мастера редиректнул сюда RestrictBarberAccess с чужой страницы — баннер
         одноразовый, гасится либо дисмиссом, либо просто новым запросом. --}}
    @if ($showBarberRestrictedBanner)
        <div class="mb-6 flex items-start gap-2.5 rounded-xl border border-info/20 bg-info/10 px-4 py-3 text-sm font-bold text-info">
            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
            <span class="flex-1">{{ __('appointments.restricted_banner') }}</span>
            <button type="button" wire:click="dismissBarberRestrictedBanner" aria-label="{{ __('common.close') }}"
                    class="shrink-0 rounded-lg p-0.5 text-info/60 transition hover:bg-info/15 hover:text-info">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>
    @endif

    {{-- Date Selector --}}
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-content/[0.06] bg-content/[0.03] p-4 backdrop-blur-md">
        <div class="flex items-center gap-3">
            <button type="button" wire:click="prevDay" title="{{ __('appointments.prev_day') }}" aria-label="{{ __('appointments.prev_day') }}"
                    class="flex h-10 w-10 items-center justify-center rounded-xl border border-content/[0.06] text-content/40 transition hover:border-content/10 hover:text-content focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brass/40">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
            </button>

            <input type="date" wire:model.live="date" aria-label="{{ __('common.date') }}"
                   class="rounded-xl border border-content/10 bg-content/5 px-4 py-2 text-sm text-content shadow-sm transition-colors focus:border-brass focus:outline-none focus:ring-1 focus:ring-brass dark:[color-scheme:dark]">

            <button type="button" wire:click="nextDay" title="{{ __('appointments.next_day') }}" aria-label="{{ __('appointments.next_day') }}"
                    class="flex h-10 w-10 items-center justify-center rounded-xl border border-content/[0.06] text-content/40 transition hover:border-content/10 hover:text-content focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brass/40">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
            </button>
        </div>

        <div class="flex flex-col items-center">
            <span class="text-xs font-bold uppercase tracking-widest text-brass-ink/60">{{ Carbon::parse($date)->translatedFormat('l') }}</span>
            <span class="text-lg font-extrabold text-content">{{ Carbon::parse($date)->translatedFormat('d F Y') }}</span>
        </div>

        <button type="button" wire:click="setDate('{{ Carbon::now('Asia/Tashkent')->toDateString() }}')"
                @class([
                    'rounded-xl px-4 py-2.5 text-xs font-bold uppercase tracking-wider transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brass/40',
                    'bg-brass text-on-brass' => $this->isToday,
                    'border border-content/[0.06] text-content/40 hover:border-content/10 hover:text-content' => ! $this->isToday,
                ])>
            {{ __('common.today') }}
        </button>
    </div>

    {{-- Заблокированное удаление: баннер живёт над таблицей, вне модалки --}}
    @error('delete')
        <div class="mb-6 flex items-start gap-2.5 rounded-xl border border-danger/20 bg-danger/10 px-4 py-3 text-sm font-bold text-danger">
            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9.303-3.376c.866 1.5-.217 3.374-1.948 3.374H4.645c-1.73 0-2.813-1.874-1.948-3.374L9.4 3.378c.866-1.5 3.032-1.5 3.898 0l6.85 11.872ZM12 17.25h.007v.008H12v-.008Z" /></svg>
            <span class="flex-1">{{ $message }}</span>
            <button type="button" wire:click="dismissDeleteError" aria-label="{{ __('common.close') }}"
                    class="shrink-0 rounded-lg p-0.5 text-danger/60 transition hover:bg-danger/15 hover:text-danger">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>
    @enderror

    {{-- Modal — мастеру недоступна: внутри поиск по всему справочнику клиентов --}}
    @if ($showForm && ! $isBarberView)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data
             x-on:keydown.escape.window="@if ($this->formHasContent) confirm(@js(__('appointments.discard_confirm'))) && @endif $wire.cancel()">
            {{-- Без wire:click: случайный тап рядом с модалкой на планшете не должен стирать заполненную форму --}}
            <div class="absolute inset-0 bg-surface/80 backdrop-blur-sm"></div>
            <div class="relative z-10 flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-content/[0.12] bg-surface-raised shadow-[0_0_0_1px_rgba(255,255,255,0.04),0_32px_64px_rgba(0,0,0,0.8)]"
                 role="dialog" aria-modal="true" aria-labelledby="appointment-modal-title"
                 x-trap.inert.noscroll="true">
                <div class="flex items-center justify-between border-b border-content/[0.06] px-6 py-4">
                    <h3 id="appointment-modal-title" class="text-sm font-bold text-content">{{ $editingId ? __('appointments.edit_title') : __('appointments.create_title') }}</h3>
                    <button type="button" wire:click="cancel"
                            @if ($this->formHasContent) wire:confirm="{{ __('appointments.discard_confirm') }}" @endif
                            title="{{ __('common.close') }}" aria-label="{{ __('common.close') }}"
                            class="flex h-8 w-8 items-center justify-center rounded-lg text-content-subtle transition hover:bg-content/[0.06] hover:text-content focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brass/40">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>
                <form wire:submit="save" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex-1 max-h-[70vh] overflow-y-auto px-6 py-6">
                        <div class="grid gap-6 sm:grid-cols-2">
                            <div>
                                <x-search-select
                                    :options="$this->filteredClients"
                                    searchModel="clientSearch"
                                    inputId="appointment-client"
                                    onSelect="selectClient"
                                    labelField="name"
                                    subLabelField="phone"
                                    placeholder="{{ __('appointments.search_client') }}"
                                    :selectedLabel="$this->selectedClient?->name"
                                    onClear="clearClient"
                                >
                                    <x-slot:label>{{ __('common.client') }}</x-slot:label>
                                </x-search-select>
                                @error('client_id') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="appointment-barber_id" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('common.barber') }}</label>
                                <div class="relative">
                                    <select id="appointment-barber_id" wire:model.live="barber_id"
                                            class="block w-full appearance-none rounded-xl border border-content/[0.08] bg-surface-sunken py-3 pl-4 pr-10 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 [&>option]:bg-surface-raised">
                                        <option value="">{{ __('appointments.select_barber') }}</option>
                                        @foreach ($this->barbers as $barber)
                                            <option value="{{ $barber->id }}" @if($barber->price)
                                                data-price="{{ $barber->price }}"
                                            @endif>
                                                {{ $barber->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <div class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-content-subtle">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                    </div>
                                </div>
                                @error('barber_id') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="appointment-form_date" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('common.date') }}</label>
                                <input id="appointment-form_date" type="date" wire:model="form_date"
                                       class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                                @error('form_date') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="appointment-payment_type" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('appointments.payment_method') }}</label>
                                <div class="relative">
                                    <select id="appointment-payment_type" wire:model.live="payment_type"
                                            class="block w-full appearance-none rounded-xl border border-content/[0.08] bg-surface-sunken py-3 pl-4 pr-10 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 [&>option]:bg-surface-raised">
                                        <option value="cash">{{ __('enums.payment_type.cash') }}</option>
                                        <option value="card">{{ __('enums.payment_type.card') }}</option>
                                        <option value="both">{{ __('enums.payment_type.both') }}</option>
                                    </select>
                                    <div class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-content-subtle">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                    </div>
                                </div>
                                @error('payment_type') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                            </div>

                            @if ($payment_type === 'both')
                                <div class="sm:col-span-2">
                                    <div class="grid grid-cols-2 gap-4 rounded-xl border border-royal/20 bg-royal/5 p-4">
                                        <div>
                                            <label for="appointment-cash_amount" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('enums.payment_type.cash') }} ({{ __('common.currency') }})</label>
                                            {{--
                                                .number обязателен на каждом денежном поле с .live: без него
                                                браузер шлёт строку «12», свойство типизировано как ?int, и
                                                ответ приходит числом 12. Livewire видит смену типа как
                                                изменение значения на сервере и перетирает то, что админ успел
                                                набрать за время запроса, — набранное «120» откатывалось в «12».
                                            --}}
                                            <div class="relative">
                                                <input id="appointment-cash_amount" type="number" wire:model.number.live="cash_amount"
                                                       placeholder="0" min="0"
                                                       class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] py-3 pl-4 pr-12 text-sm text-content outline-none transition focus:border-success/40 focus:ring-1 focus:ring-success/20">
                                                <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[10px] font-medium text-content/25">{{ __('common.currency') }}</span>
                                            </div>
                                            @error('cash_amount') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label for="appointment-card_amount" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('enums.payment_type.card') }} ({{ __('common.currency') }})</label>
                                            <div class="relative">
                                                <input id="appointment-card_amount" type="number" wire:model.number.live="card_amount"
                                                       placeholder="0" min="0"
                                                       class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] py-3 pl-4 pr-12 text-sm text-content outline-none transition focus:border-info/40 focus:ring-1 focus:ring-info/20">
                                                <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[10px] font-medium text-content/25">{{ __('common.currency') }}</span>
                                            </div>
                                            @error('card_amount') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                                        </div>
                                    </div>
                                </div>
                            @endif

                            {{-- Debt --}}
                            <div class="sm:col-span-2">
                                <div @class([
                                    'rounded-xl border p-4 transition-colors',
                                    'border-danger/20 bg-danger/5' => $debtEnabled,
                                    'border-content/[0.06] bg-content/[0.02]' => ! $debtEnabled,
                                ])>
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <label @class([
                                                'text-xs font-semibold transition-colors',
                                                'text-danger/80' => $debtEnabled,
                                                'text-content/50' => ! $debtEnabled,
                                            ])>{{ __('appointments.on_debt') }}</label>
                                            <p class="mt-0.5 text-[10px] text-content-muted">{{ __('appointments.debt_hint') }}</p>
                                        </div>
                                        <button type="button" wire:click="$toggle('debtEnabled')"
                                                role="switch" aria-checked="{{ $debtEnabled ? 'true' : 'false' }}"
                                                aria-label="{{ __('appointments.on_debt') }}"
                                                @class([
                                                    'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors outline-none focus-visible:ring-2 focus-visible:ring-brass/40',
                                                    'bg-danger' => $debtEnabled,
                                                    'bg-content/[0.12]' => ! $debtEnabled,
                                                ])>
                                            <span @class([
                                                'inline-block h-4 w-4 transform rounded-full bg-content shadow transition-transform',
                                                'translate-x-6' => $debtEnabled,
                                                'translate-x-1' => ! $debtEnabled,
                                            ])></span>
                                        </button>
                                    </div>
                                    @if ($debtEnabled)
                                        <div class="relative mt-3">
                                            <input type="number" wire:model.number.live="debt_amount" aria-label="{{ __('appointments.on_debt') }}"
                                                   placeholder="0" min="0"
                                                   class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] py-3 pl-4 pr-12 text-sm text-content outline-none transition focus:border-danger/40 focus:ring-1 focus:ring-danger/20">
                                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[10px] font-medium text-content/25">{{ __('common.currency') }}</span>
                                        </div>
                                        <p class="mt-2 text-[10px] text-danger/70">{{ __('appointments.debt_client_required') }}</p>
                                    @endif
                                    {{-- Вне @if: ошибка «долг меньше уже принятого» приходит при выключенном тумблере --}}
                                    @error('debt_amount') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                                    @if ($debtAlreadyPaid > 0)
                                        <p class="mt-1.5 text-[10px] text-content/40">
                                            {{ __('appointments.debt_already_paid', ['amount' => number_format($debtAlreadyPaid, 0, '.', ' ')]) }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            {{-- Services --}}
                            <div class="sm:col-span-2">
                                <div class="mb-3 flex items-center justify-between gap-2">
                                    <label class="text-xs font-semibold text-content/50">{{ __('common.services') }}</label>
                                    <button type="button" wire:click="addService"
                                            class="flex items-center gap-1.5 rounded-lg border border-content/[0.08] bg-content/[0.02] px-3 py-1.5 text-xs font-bold text-content/60 transition hover:border-brass/40 hover:bg-brass/5 hover:text-brass-ink">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        {{ __('appointments.add_service') }}
                                    </button>
                                </div>

                                @error('selectedServices') <p class="mb-3 text-xs text-danger">{{ $message }}</p> @enderror
                                {{-- Правила дают ключи вида selectedServices.0.amount — точный @error их не ловит --}}
                                @foreach ($errors->get('selectedServices.*') as $serviceError)
                                    <p class="mb-3 text-xs text-danger">{{ $serviceError[0] }}</p>
                                @endforeach

                                @php
                                    $usedServiceIds = collect($selectedServices)
                                        ->pluck('service_id')
                                        ->filter()
                                        ->map(fn ($id) => (int) $id)
                                        ->values()
                                        ->toArray();
                                @endphp

                                <div class="overflow-hidden rounded-xl border border-content/[0.06]">
                                    @forelse ($selectedServices as $i => $row)
                                        @php
                                            $otherUsed = collect($usedServiceIds)
                                                ->filter(fn ($id) => $id !== (int) ($row['service_id'] ?? 0))
                                                ->values()
                                                ->toArray();
                                            $isDuplicate = isset($row['service_id']) && $row['service_id'] !== null &&
                                                collect($usedServiceIds)->filter(fn ($id) => $id === (int) $row['service_id'])->count() > 1;
                                        @endphp
                                        <div wire:key="svc-row-{{ $i }}" @class([
                                            'flex items-center gap-3 px-4 py-3',
                                            'border-b border-content/[0.04]' => ! $loop->last,
                                            'bg-danger/5' => $isDuplicate,
                                            'bg-content/[0.02]' => ! $isDuplicate,
                                        ])>
                                            <span class="w-5 shrink-0 text-center text-xs font-bold text-content-muted">{{ $i + 1 }}</span>
                                            <select wire:model.live="selectedServices.{{ $i }}.service_id"
                                                    aria-label="{{ __('appointments.select_service') }}"
                                                    @class([
                                                        'min-w-0 flex-1 rounded-lg border bg-transparent px-3 py-2 text-sm text-content outline-none transition [&>option]:bg-surface',
                                                        'border-danger/40 focus:border-danger/60' => $isDuplicate,
                                                        'border-content/[0.08] focus:border-brass/40 focus:ring-1 focus:ring-brass/20' => ! $isDuplicate,
                                                    ])>
                                                <option value="">{{ __('appointments.select_service') }}</option>
                                                @foreach ($this->services as $service)
                                                    <option value="{{ $service->id }}"
                                                            @disabled(in_array($service->id, $otherUsed))>
                                                        {{ $service->name }} ({{ $service->duration_minutes }} {{ __('common.minutes_short') }})
                                                    </option>
                                                @endforeach
                                            </select>
                                            @if ($isDuplicate)
                                                <span class="shrink-0 text-[10px] font-bold text-danger">{{ __('appointments.duplicate') }}</span>
                                            @endif
                                            <div class="relative w-36 shrink-0">
                                                <input type="number" wire:model.number.live="selectedServices.{{ $i }}.amount" aria-label="{{ __('common.amount') }}"
                                                       placeholder="0" min="0"
                                                       class="block w-full rounded-lg border border-content/[0.08] bg-transparent py-2 pl-3 pr-10 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                                                <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[10px] font-medium text-content/25">{{ __('common.currency') }}</span>
                                            </div>
                                            <button type="button" wire:click="removeService({{ $i }})"
                                                    title="{{ __('appointments.remove_service') }}" aria-label="{{ __('appointments.remove_service') }}"
                                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-content-subtle transition hover:bg-danger/10 hover:text-danger focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brass/40">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                            </button>
                                        </div>
                                    @empty
                                        <div class="flex flex-col items-center gap-2 py-8 text-center">
                                            <svg class="h-8 w-8 text-content/10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z" /></svg>
                                            <p class="text-xs text-content/25">{{ __('appointments.no_services_hint') }}</p>
                                        </div>
                                    @endforelse
                                </div>

                                @if (count($selectedServices) > 0)
                                    <div class="mt-2.5 flex items-center justify-end gap-2 rounded-xl border border-brass/10 bg-brass/5 px-4 py-2.5">
                                        <span class="text-xs text-content/40">{{ __('common.total') }}:</span>
                                        <span class="text-sm font-bold text-brass-ink">{{ number_format((int) $price, 0, '.', ' ') }} {{ __('common.currency') }}</span>
                                    </div>
                                @endif
                            </div>

                            {{-- Time selects --}}
                            <div class="sm:col-span-2">
                                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <label class="text-xs font-semibold text-content/50">{{ __('common.time') }}</label>
                                    <div class="flex items-center gap-1 rounded-xl border border-content/[0.06] bg-content/[0.03] p-1">
                                        @foreach ([15 => '15 '.__('common.minutes_short'), 30 => '30 '.__('common.minutes_short'), 60 => __('appointments.one_hour')] as $step => $label)
                                            <button type="button" wire:click="setTimeStep({{ $step }})"
                                                    aria-pressed="{{ $timeStep === $step ? 'true' : 'false' }}"
                                                    @class([
                                                        'rounded-lg px-3 py-1 text-xs font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brass/40',
                                                        'bg-brass text-black' => $timeStep === $step,
                                                        'text-content/40 hover:text-content' => $timeStep !== $step,
                                                    ])>
                                                {{ $label }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label for="appointment-form_start_time" class="mb-1.5 block text-xs font-semibold text-content/40">{{ __('appointments.start') }}</label>
                                        <select id="appointment-form_start_time" wire:model.live="form_start_time"
                                                class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 [&>option]:bg-surface">
                                            <option value="">{{ __('appointments.select_placeholder') }}</option>
                                            @foreach ($this->timeSlots as $slot)
                                                <option value="{{ $slot }}">{{ $slot }}</option>
                                            @endforeach
                                        </select>
                                        @error('form_start_time') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label for="appointment-form_end_time" class="mb-1.5 block text-xs font-semibold text-content/40">{{ __('appointments.end') }}</label>
                                        <select id="appointment-form_end_time" wire:model.live="form_end_time"
                                                class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 [&>option]:bg-surface">
                                            <option value="">{{ __('appointments.select_placeholder') }}</option>
                                            @foreach ($this->timeSlots as $slot)
                                                <option value="{{ $slot }}">{{ $slot }}</option>
                                            @endforeach
                                        </select>
                                        @error('form_end_time') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="sm:col-span-2">
                                <label for="appointment-note" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('appointments.note') }}</label>
                                <textarea id="appointment-note" wire:model="note" rows="2" placeholder="{{ __('appointments.note_placeholder') }}"
                                          class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20"></textarea>
                                @error('note') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center justify-end gap-3 border-t border-content/[0.06] px-6 py-4">
                        <button type="button" wire:click="cancel"
                                @if ($this->formHasContent) wire:confirm="{{ __('appointments.discard_confirm') }}" @endif
                                class="rounded-xl border border-content/[0.08] px-5 py-2.5 text-sm font-bold text-content/60 transition hover:bg-content/[0.06] hover:text-content">
                            {{ __('common.cancel') }}
                        </button>
                        <x-submit-button>{{ $editingId ? __('appointments.save_changes') : __('appointments.create') }}</x-submit-button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content-muted">
                        <th class="cursor-pointer px-6 py-4 transition hover:text-content" wire:click="sortBy('starts_at')">
                            {{ __('common.time') }}
                            @if ($sortField === 'starts_at')
                                <span class="ml-1">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                        <th class="px-6 py-4">{{ __('appointments.client_note') }}</th>
                        {{-- В своей же таблице мастер и так знает, кто мастер --}}
                        @unless ($isBarberView)
                            <th class="hidden px-6 py-4 md:table-cell">{{ __('common.barber') }}</th>
                        @endunless
                        <th class="hidden px-6 py-4 md:table-cell">{{ __('appointments.services_total') }}</th>
                        <th class="cursor-pointer px-6 py-4 transition hover:text-content" wire:click="sortBy('status')">
                            {{ __('common.status') }}
                            @if ($sortField === 'status')
                                <span class="ml-1">{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </th>
                        <th class="px-6 py-4 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-content/[0.04]">
                    @forelse ($this->appointments as $appointment)
                        <tr wire:key="appointment-{{ $appointment->id }}" class="transition-colors hover:bg-content/[0.02]">
                            <td class="whitespace-nowrap px-6 py-4">
                                <div class="font-bold text-content">{{ $appointment->starts_at->format('H:i') }}</div>
                                <div class="text-[10px] text-content-muted">{{ $appointment->ends_at->format('H:i') }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-bold text-content">{{ $appointment->client?->name }}</div>
                                <div class="text-xs text-brass-ink/60">{{ $appointment->client?->formattedPhone }}</div>
                                @if ($appointment->note)
                                    <div class="mt-1 flex items-start gap-1.5 text-[11px] text-content/40">
                                        <svg class="mt-0.5 h-3 w-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" /></svg>
                                        <span class="italic">{{ $appointment->note }}</span>
                                    </div>
                                @endif
                                <div class="mt-1 text-[10px] text-content-muted md:hidden">
                                    @unless ($isBarberView)
                                        {{ $appointment->barber?->name }} ·
                                    @endunless
                                    {{ $appointment->services->pluck('name')->join(', ') }}
                                </div>
                            </td>
                            @unless ($isBarberView)
                            <td class="hidden px-6 py-4 md:table-cell">
                                <div class="flex items-center gap-2">
                                    @if ($appointment->barber?->photoUrl)
                                        <img src="{{ $appointment->barber->photoUrl }}" alt="{{ $appointment->barber->name }}" class="h-6 w-6 rounded-full object-cover">
                                    @endif
                                    <span class="font-medium text-content/60">{{ $appointment->barber?->name }}</span>
                                </div>
                            </td>
                            @endunless
                            <td class="hidden px-6 py-4 md:table-cell">
                                <div class="space-y-0.5">
                                    @foreach ($appointment->services as $service)
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="text-content/60">{{ $service->name }}</span>
                                            <span class="text-[10px] font-bold text-content/40">{{ number_format($service->pivot->amount, 0, '.', ' ') }} {{ __('common.currency') }}</span>
                                        </div>
                                    @endforeach
                                </div>
                                @if ($appointment->services->isNotEmpty())
                                    <div class="mt-1 border-t border-content/[0.04] pt-1 flex items-center justify-between gap-2">
                                        <span @class([
                                            'text-[10px] font-bold',
                                            'text-brass-ink' => $appointment->price !== null,
                                            'text-content-muted' => $appointment->price === null,
                                        ])>
                                            {{ __('common.total') }}: {{ $appointment->formattedPrice }}
                                        </span>
                                        @if ($appointment->payment_type === \App\Enums\PaymentType::Both && ($appointment->cash_amount || $appointment->card_amount))
                                            <div class="flex flex-col items-end gap-0.5">
                                                <span class="inline-flex items-center rounded-full bg-royal/10 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider text-royal">
                                                    {{ $appointment->payment_type->label() }}
                                                </span>
                                                <span class="text-[9px] text-success/70">{{ __('appointments.cash_short') }}: {{ number_format((int) $appointment->cash_amount, 0, '.', ' ') }} {{ __('common.currency') }}</span>
                                                <span class="text-[9px] text-info/70">{{ __('appointments.card_short') }}: {{ number_format((int) $appointment->card_amount, 0, '.', ' ') }} {{ __('common.currency') }}</span>
                                            </div>
                                        @else
                                            <span @class([
                                                'inline-flex items-center rounded-full px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider',
                                                $appointment->payment_type?->badgeClasses() ?? \App\Enums\PaymentType::Cash->badgeClasses(),
                                            ])>
                                                {{ $appointment->payment_type?->label() ?? __('enums.payment_type.cash') }}
                                            </span>
                                        @endif
                                    </div>
                                    @if ($appointment->hasDebt)
                                        <div class="mt-1 flex items-center gap-1.5 rounded-lg bg-danger/10 px-2 py-1">
                                            <svg class="h-3 w-3 text-danger" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                            <span class="text-[10px] font-bold text-danger">{{ __('common.debt') }}: {{ $appointment->formattedDebt }}</span>
                                        </div>
                                    @endif
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @php($badge = $appointment->status->badgeClasses())
                                @php($label = $appointment->status->label())
                                <span @class([
                                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider',
                                    'bg-success/10 text-success' => str_contains($badge, 'emerald'),
                                    'bg-brass/10 text-brass-ink' => str_contains($badge, 'amber'),
                                    'bg-info/10 text-info' => str_contains($badge, 'blue'),
                                    'bg-danger/10 text-danger' => str_contains($badge, 'rose'),
                                    'bg-content/10 text-content/40' => str_contains($badge, 'zinc'),
                                ])>
                                    {{ $label }}
                                </span>
                            </td>
                            {{-- Статусные кнопки доступны и мастеру (только для своих строк — им и
                                 отдаёт appointments() выше), редактирование/удаление — только админу. --}}
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($appointment->status === AppointmentStatus::Pending)
                                        <button type="button" wire:click="markConfirmed({{ $appointment->id }})"
                                                title="{{ __('common.confirm') }}" aria-label="{{ __('common.confirm') }}"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg bg-info/10 text-info transition hover:bg-info hover:text-black focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brass/40">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        </button>
                                        <button type="button" wire:click="markCancelled({{ $appointment->id }})"
                                                wire:confirm="{{ __('appointments.cancel_confirm') }}"
                                                title="{{ __('appointments.cancel_action') }}" aria-label="{{ __('appointments.cancel_action') }}"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg bg-danger/10 text-danger transition hover:bg-danger hover:text-black focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brass/40">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                        </button>
                                    @elseif ($appointment->status === AppointmentStatus::Confirmed)
                                        <button type="button" wire:click="markCompleted({{ $appointment->id }})"
                                                wire:confirm="{{ __('appointments.complete_confirm') }}"
                                                title="{{ __('appointments.complete') }}" aria-label="{{ __('appointments.complete') }}"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg bg-success/10 text-success transition hover:bg-success hover:text-black focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brass/40">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                        </button>
                                        <button type="button" wire:click="markCancelled({{ $appointment->id }})"
                                                wire:confirm="{{ __('appointments.cancel_confirm') }}"
                                                title="{{ __('appointments.cancel_action') }}" aria-label="{{ __('appointments.cancel_action') }}"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg bg-danger/10 text-danger transition hover:bg-danger hover:text-black focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brass/40">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                        </button>
                                    @else
                                        <button type="button" wire:click="markConfirmed({{ $appointment->id }})"
                                                wire:confirm="{{ __('appointments.return_to_confirmed_confirm') }}"
                                                title="{{ __('appointments.return_to_confirmed') }}" aria-label="{{ __('appointments.return_to_confirmed') }}"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg bg-content/[0.04] text-content/40 transition hover:bg-brass/10 hover:text-brass-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brass/40">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" /></svg>
                                        </button>
                                    @endif
                                    @unless ($isBarberView)
                                        <button type="button" wire:click="edit({{ $appointment->id }})"
                                                title="{{ __('common.edit') }}" aria-label="{{ __('common.edit') }}"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-content/[0.06] text-content/40 transition hover:border-content/10 hover:text-content focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brass/40">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" /></svg>
                                        </button>
                                        <button type="button" wire:click="delete({{ $appointment->id }})"
                                                wire:confirm="{{ __('appointments.delete_confirm') }}"
                                                title="{{ __('common.delete') }}" aria-label="{{ __('common.delete') }}"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg border border-content/[0.06] text-content-subtle transition hover:text-danger focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brass/40">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                        </button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $isBarberView ? 5 : 6 }}" class="px-6 py-12 text-center text-content-muted">
                                @if ($this->isUnlinkedBarber)
                                    {{ __('appointments.barber_unlinked') }}
                                @else
                                    {{ __('appointments.empty') }}
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
