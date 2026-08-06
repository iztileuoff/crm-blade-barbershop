<?php

use App\Models\Client;
use App\Models\SmsMessage;
use App\Services\SmsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.app')]
class extends Component
{
    use WithPagination;

    public string $status = '';

    public string $context = '';

    public string $search = '';

    public string $from = '';

    public string $to = '';

    public bool $balanceChecked = false;

    public ?string $balance = null;

    /** @return array<string, string> */
    public function contextLabels(): array
    {
        return [
            'reminder' => __('sms.context_reminder'),
            'retention' => __('sms.context_retention'),
            'broadcast' => __('sms.context_broadcast'),
            'manual' => __('sms.context_manual'),
        ];
    }

    /** @return array<string, string> */
    public function deliveryLabels(): array
    {
        return [
            'delivered' => __('sms.delivery_delivered'),
            'undelivered' => __('sms.delivery_undelivered'),
            'rejected' => __('sms.delivery_rejected'),
            'waiting' => __('sms.delivery_waiting'),
        ];
    }

    #[Computed]
    public function messages()
    {
        return $this->applyFilters(SmsMessage::query()->with('client'))
            ->latest()
            ->paginate(25);
    }

    /**
     * Все фильтры аудита в одном месте: статус, тип, получатель и период.
     *
     * @param  Builder<SmsMessage>  $query
     * @return Builder<SmsMessage>
     */
    private function applyFilters(Builder $query): Builder
    {
        $term = trim($this->search);
        $digits = $term !== '' ? Client::searchDigits($term) : null;
        $from = $this->dateBound($this->from);
        $to = $this->dateBound($this->to);

        return $query
            ->when($this->status !== '', function (Builder $q) {
                return in_array($this->status, ['sent', 'failed'], true)
                    ? $q->where('status', $this->status)
                    : $q->where('delivery_status', $this->status);
            })
            ->when($this->context !== '', fn (Builder $q) => $q->where('context', $this->context))
            ->when($term !== '', function (Builder $q) use ($term, $digits) {
                $q->where(function (Builder $inner) use ($term, $digits) {
                    $inner->whereHas('client', fn (Builder $c) => $c->where('name', 'like', '%'.$term.'%'));

                    if ($digits !== null) {
                        $inner->orWhere('phone', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->when($from !== null, fn (Builder $q) => $q->where('created_at', '>=', $from->copy()->startOfDay()))
            ->when($to !== null, fn (Builder $q) => $q->where('created_at', '<=', $to->copy()->endOfDay()));
    }

    /**
     * Граница периода. Поля дат клиентские, поэтому разбор защищён: кривое
     * значение должно просто не фильтровать, а не ронять страницу аудита.
     */
    private function dateBound(string $value): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Exception) {
            return null;
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'status', 'context', 'from', 'to']);
        $this->resetPage();
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return trim($this->search) !== '' || $this->status !== '' || $this->context !== ''
            || $this->from !== '' || $this->to !== '';
    }

    #[Computed]
    public function sentCount(): int
    {
        return SmsMessage::where('status', 'sent')->count();
    }

    #[Computed]
    public function failedCount(): int
    {
        return SmsMessage::where('status', 'failed')->count();
    }

    /**
     * Метрики за периоды (сегодня / 7 / 30 дней): отправлено, ошибки, оценка расходов,
     * и данные для графика по дням за последние 30 дней.
     *
     * @return array{
     *     sent: array{today:int, week:int, month:int},
     *     failed: array{today:int, week:int, month:int},
     *     cost: array{today:int, week:int, month:int},
     *     chart: list<array{label:string, sent:int, failed:int}>,
     *     chartMax: int
     * }
     */
    #[Computed]
    public function metrics(): array
    {
        $now = Carbon::now();
        $today = $now->copy()->startOfDay();
        $d7 = $now->copy()->subDays(7);
        $d30 = $now->copy()->subDays(30);

        $sent30 = SmsMessage::query()
            ->where('status', 'sent')
            ->where('created_at', '>=', $d30)
            ->get(['phone', 'parts', 'created_at']);

        $failed30 = SmsMessage::query()
            ->where('status', 'failed')
            ->where('created_at', '>=', $d30)
            ->get(['created_at']);

        $costOf = fn ($collection): int => (int) $collection->sum(
            fn ($m) => ($m->parts ?: 1) * SmsService::tariffForPhone($m->phone)
        );

        $sentToday = $sent30->filter(fn ($m) => $m->created_at->gte($today));
        $sentWeek = $sent30->filter(fn ($m) => $m->created_at->gte($d7));

        // График: по дню за последние 30 дней.
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = $now->copy()->subDays($i)->startOfDay();
            $days[$day->format('Y-m-d')] = ['label' => $day->format('d.m'), 'sent' => 0, 'failed' => 0];
        }
        foreach ($sent30 as $m) {
            $k = $m->created_at->format('Y-m-d');
            if (isset($days[$k])) {
                $days[$k]['sent']++;
            }
        }
        foreach ($failed30 as $m) {
            $k = $m->created_at->format('Y-m-d');
            if (isset($days[$k])) {
                $days[$k]['failed']++;
            }
        }

        $chart = array_values($days);
        $chartMax = max(1, ...array_map(fn ($d) => $d['sent'] + $d['failed'], $chart));

        return [
            'sent' => [
                'today' => $sentToday->count(),
                'week' => $sentWeek->count(),
                'month' => $sent30->count(),
            ],
            'failed' => [
                'today' => $failed30->filter(fn ($m) => $m->created_at->gte($today))->count(),
                'week' => $failed30->filter(fn ($m) => $m->created_at->gte($d7))->count(),
                'month' => $failed30->count(),
            ],
            'cost' => [
                'today' => $costOf($sentToday),
                'week' => $costOf($sentWeek),
                'month' => $costOf($sent30),
            ],
            'chart' => $chart,
            'chartMax' => $chartMax,
        ];
    }

    /**
     * Баланс Eskiz для плитки метрик. Грузится лениво через wire:init — а не
     * при mount() — чтобы страница истории не ждала внешний API на первом рендере.
     */
    public function loadBalance(SmsService $sms): void
    {
        $this->balance = $sms->balance();
        $this->balanceChecked = true;
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedContext(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }
}; ?>

<div class="animate-fade-in-up">
    @php($m = $this->metrics())

    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('sms.history_title') }}</h1>
            <p class="mt-1 text-sm text-content/40">
                {{ __('sms.sent_label') }}: <span class="font-bold text-success">{{ $this->sentCount }}</span>
                · {{ __('sms.errors_label') }}: <span class="font-bold text-danger">{{ $this->failedCount }}</span>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative">
                <svg class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-content/20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('sms.search_placeholder') }}"
                       class="w-56 rounded-xl border border-content/[0.08] bg-content/[0.04] py-2.5 pl-10 pr-4 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
            </div>
            <select wire:model.live="context"
                    class="rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-2.5 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 dark:[color-scheme:dark]">
                <option value="">{{ __('sms.all_types') }}</option>
                @foreach($this->contextLabels() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="status"
                    class="rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-2.5 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 dark:[color-scheme:dark]">
                <option value="">{{ __('sms.all_statuses') }}</option>
                <option value="sent">{{ __('sms.sent') }}</option>
                <option value="failed">{{ __('sms.error') }}</option>
                @foreach($this->deliveryLabels() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Период: без него аудит «дошла ли SMS во вторник?» невозможен --}}
    <div class="mb-6 flex flex-wrap items-end gap-3">
        <div>
            <label for="sms-from" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('sms.period_from') }}</label>
            <input id="sms-from" type="date" wire:model.live="from" max="{{ $to ?: null }}"
                   class="rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-2.5 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 dark:[color-scheme:dark]">
        </div>
        <div>
            <label for="sms-to" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('sms.period_to') }}</label>
            <input id="sms-to" type="date" wire:model.live="to" min="{{ $from ?: null }}"
                   class="rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-2.5 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 dark:[color-scheme:dark]">
        </div>
        @if ($this->hasFilters)
            <button type="button" wire:click="resetFilters"
                    class="rounded-xl border border-content/[0.08] px-4 py-2.5 text-sm font-bold text-content/50 transition hover:bg-content/[0.06] hover:text-content">
                {{ __('sms.reset_filters') }}
            </button>
        @endif
        <div wire:loading.delay wire:target="search,status,context,from,to,gotoPage,nextPage,previousPage" class="flex items-center gap-2 pb-1 text-xs text-content/40">
            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
            {{ __('common.loading') }}
        </div>
    </div>

    {{-- Метрики по периодам. Считаются по всем отправкам: они про расход SMS,
         а не про выборку — и это должно быть написано, а не угадываться. --}}
    <p class="mb-2 text-xs text-content/30">{{ __('sms.metrics_scope') }}</p>
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach(['today' => __('sms.period_today'), 'week' => __('sms.period_7days'), 'month' => __('sms.period_30days')] as $key => $label)
            <div class="rounded-2xl border border-content/[0.06] bg-content/[0.03] p-5 shadow-xl backdrop-blur-md">
                <p class="text-xs font-bold uppercase tracking-wider text-content/30">{{ $label }}</p>
                <div class="mt-3 flex items-end justify-between gap-2">
                    <div>
                        <p class="text-2xl font-bold text-content">{{ $m['sent'][$key] }}</p>
                        <p class="text-xs text-content/40">{{ __('sms.sent_label') }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-bold text-danger">{{ $m['failed'][$key] }}</p>
                        <p class="text-xs text-content/40">{{ __('sms.errors_label') }}</p>
                    </div>
                </div>
                <div class="mt-3 border-t border-content/[0.06] pt-3">
                    <p class="text-sm font-bold text-brass-ink">≈ {{ number_format($m['cost'][$key], 0, '.', ' ') }} {{ __('common.currency') }}</p>
                    <p class="text-xs text-content/40">{{ __('sms.cost_estimate') }}</p>
                </div>
            </div>
        @endforeach

        {{-- Баланс Eskiz: грузится лениво через wire:init, чтобы не задерживать
             первый рендер страницы — до ответа плитка показывает нейтральный плейсхолдер. --}}
        <div wire:init="loadBalance" class="rounded-2xl border border-content/[0.06] bg-content/[0.03] p-5 shadow-xl backdrop-blur-md">
            <p class="text-xs font-bold uppercase tracking-wider text-content/30">{{ __('sms.balance') }}</p>
            <div class="mt-3 flex items-end justify-between gap-2">
                <div>
                    @if (! $balanceChecked)
                        <p class="text-2xl font-bold text-content/20">···</p>
                        <p class="text-xs text-content/40">{{ __('sms.checking') }}</p>
                    @elseif ($balance !== null)
                        <p class="text-2xl font-bold text-content">{{ $balance }}</p>
                        <p class="text-xs text-content/40">{{ __('common.currency') }}</p>
                    @else
                        <p class="text-2xl font-bold text-content/30">—</p>
                        <p class="text-xs text-content/40">{{ __('sms.balance_unavailable') }}</p>
                    @endif
                </div>
                <svg wire:loading wire:target="loadBalance" class="h-4 w-4 animate-spin text-content/30" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            </div>
        </div>
    </div>

    {{-- График по дням --}}
    <div class="mb-6 rounded-2xl border border-content/[0.06] bg-content/[0.03] p-6 shadow-xl backdrop-blur-md">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm font-bold text-content">{{ __('sms.chart_title') }}</h3>
            <div class="flex items-center gap-4 text-xs text-content/40">
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-brass"></span>{{ __('sms.sent_label') }}</span>
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-danger"></span>{{ __('sms.errors_label') }}</span>
            </div>
        </div>
        <div class="flex h-32 items-end gap-1">
            @foreach($m['chart'] as $day)
                <div class="flex flex-1 flex-col justify-end gap-px" title="{{ $day['label'] }}: {{ $day['sent'] }} / {{ $day['failed'] }}">
                    @if($day['failed'] > 0)
                        <div class="rounded-t bg-danger/60" style="height: {{ max(3, round($day['failed'] / $m['chartMax'] * 100)) }}%"></div>
                    @endif
                    @if($day['sent'] > 0)
                        <div class="bg-brass/70 {{ $day['failed'] > 0 ? '' : 'rounded-t' }}" style="height: {{ max(3, round($day['sent'] / $m['chartMax'] * 100)) }}%"></div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <div wire:loading.class="opacity-40 pointer-events-none" wire:target="search,status,context,from,to,gotoPage,nextPage,previousPage"
         class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div wire:loading wire:target="search,status,context,from,to,gotoPage,nextPage,previousPage"
             class="flex items-center gap-2 border-b border-content/[0.06] bg-content/[0.03] px-6 py-3 text-xs text-content/40">
            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            {{ __('common.loading') }}
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content/30">
                        <th class="px-6 py-4">{{ __('sms.col_recipient') }}</th>
                        <th class="px-6 py-4">{{ __('sms.col_message') }}</th>
                        <th class="hidden px-6 py-4 sm:table-cell">{{ __('sms.col_type') }}</th>
                        <th class="px-6 py-4">{{ __('common.status') }}</th>
                        <th class="hidden px-6 py-4 sm:table-cell">{{ __('common.date') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-content/[0.04]">
                    @forelse ($this->messages as $message)
                        <tr class="transition-colors hover:bg-content/[0.02]">
                            <td class="whitespace-nowrap px-6 py-4">
                                <div class="font-bold text-content">{{ $message->client?->name ?? '—' }}</div>
                                <div class="text-xs text-brass-ink/60">{{ \App\Models\Client::formatPhone($message->phone) }}</div>
                                {{-- На телефоне колонка даты скрыта, а это главное на странице аудита --}}
                                <div class="text-[10px] text-content/40 sm:hidden">{{ $message->created_at?->format('d.m H:i') }}</div>
                            </td>
                            <td class="max-w-md px-6 py-4 text-content/60">{{ $message->message }}</td>
                            <td class="hidden whitespace-nowrap px-6 py-4 text-content/40 sm:table-cell">
                                {{ $this->contextLabels()[$message->context] ?? '—' }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-col items-start gap-1">
                                    @if ($message->isSent())
                                        <span class="inline-flex items-center rounded-full bg-success/10 px-2.5 py-1 text-xs font-bold text-success">{{ __('sms.sent') }}</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-danger/10 px-2.5 py-1 text-xs font-bold text-danger">{{ __('sms.error') }}</span>
                                    @endif
                                    @if ($message->delivery_status)
                                        @php($delivered = $message->delivery_status === 'delivered')
                                        @php($rejected = in_array($message->delivery_status, ['rejected', 'undelivered'], true))
                                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-bold
                                            {{ $delivered ? 'bg-success/10 text-success' : ($rejected ? 'bg-danger/10 text-danger' : 'bg-content/10 text-content/50') }}">
                                            {{ $this->deliveryLabels()[$message->delivery_status] ?? $message->delivery_status }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="hidden whitespace-nowrap px-6 py-4 text-content/40 sm:table-cell">{{ $message->created_at->format('d.m.Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-content/20">
                                {{ $this->hasFilters ? __('common.nothing_found') : __('sms.empty_history') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">
        {{ $this->messages->links() }}
    </div>
</div>
