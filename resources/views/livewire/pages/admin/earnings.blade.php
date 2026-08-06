<?php

use App\Models\Barber;
use App\Support\BarberEarnings;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    /**
     * Профиль мастера, привязанный к текущему аккаунту, — из сессии, а не из
     * свойства компонента. У мастера без привязки и у большинства админов
     * это null, и тогда экран показывает явное пустое состояние, а не чей-то
     * чужой заработок.
     */
    private function sessionBarberProfileId(): ?int
    {
        return auth()->user()?->barber?->id;
    }

    #[Computed]
    public function barber(): ?Barber
    {
        $id = $this->sessionBarberProfileId();

        return $id ? Barber::with('media')->find($id) : null;
    }

    /**
     * Три суммы за период — та же формула, что и в Telegram-боте
     * (BarberMenuHandler::earnings()): обе точки входа зовут
     * BarberEarnings::periodShare(), поэтому бот и CRM не могут разъехаться
     * в цифрах.
     *
     * @return array{today: int, week: int, month: int}
     */
    #[Computed]
    public function earnings(): array
    {
        $barber = $this->barber;

        if ($barber === null) {
            return ['today' => 0, 'week' => 0, 'month' => 0];
        }

        $now = Carbon::now('Asia/Tashkent');

        return [
            'today' => BarberEarnings::periodShare($barber, $now->copy()->startOfDay(), $now->copy()->endOfDay()),
            'week' => BarberEarnings::periodShare($barber, $now->copy()->startOfWeek(), $now->copy()->endOfWeek()),
            'month' => BarberEarnings::periodShare($barber, $now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
        ];
    }

    #[Computed]
    public function salaryPercent(): int
    {
        return $this->barber?->salary_percent ?? BarberEarnings::DEFAULT_SALARY_PERCENT;
    }

    public function formatSum(int $value): string
    {
        return number_format($value, 0, '.', ' ').' '.__('common.currency');
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('earnings.title') }}</h1>
            <p class="mt-1 text-sm text-content/40">{{ __('earnings.subtitle') }}</p>
        </div>
    </div>

    @if ($this->barber === null)
        {{-- Ни у мастера без связки, ни у большинства админов профиля мастера
             нет — показываем это явно, а не пустые/нулевые карточки. --}}
        <div class="flex flex-col items-center gap-3 rounded-2xl border border-content/[0.06] bg-content/[0.03] px-6 py-16 text-center shadow-xl backdrop-blur-md">
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-content/[0.06] text-content/30">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
            </div>
            <p class="max-w-sm text-sm text-content/40">
                {{ auth()->user()?->isBarber() ? __('earnings.no_profile_barber') : __('earnings.no_profile_admin') }}
            </p>
        </div>
    @else
        {{-- Профиль мастера --}}
        <div class="mb-8 flex flex-wrap items-center gap-4 rounded-2xl border border-content/[0.06] bg-content/[0.03] p-5 backdrop-blur-md">
            @if ($this->barber->photoUrl)
                <img src="{{ $this->barber->photoUrl }}" alt="{{ $this->barber->name }}" class="h-14 w-14 shrink-0 rounded-2xl object-cover">
            @else
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-brass/10 text-lg font-extrabold text-brass-ink">{{ mb_substr($this->barber->name, 0, 1) }}</div>
            @endif
            <div>
                <div class="text-lg font-extrabold text-content">{{ $this->barber->name }}</div>
                <div class="mt-1 inline-flex items-center rounded-full bg-brass/10 px-2.5 py-0.5 text-xs font-bold text-brass-ink">
                    {{ __('earnings.percent_label') }}: {{ $this->salaryPercent }}%
                </div>
            </div>
        </div>

        {{-- Три периода — ровно как в Telegram-боте --}}
        <div class="grid gap-5 sm:grid-cols-3">
            <div class="overflow-hidden rounded-2xl border border-success/20 bg-gradient-to-br from-success/10 to-success/[0.02] p-6 shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-widest text-success/70">{{ __('earnings.today') }}</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-success/15 text-success">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                    </div>
                </div>
                <div class="mt-4 font-display text-3xl font-bold tabular-nums text-content">{{ $this->formatSum($this->earnings['today']) }}</div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-info/20 bg-gradient-to-br from-info/10 to-info/[0.02] p-6 shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-widest text-info/70">{{ __('earnings.week') }}</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-info/15 text-info">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                    </div>
                </div>
                <div class="mt-4 font-display text-3xl font-bold tabular-nums text-content">{{ $this->formatSum($this->earnings['week']) }}</div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-royal/20 bg-gradient-to-br from-royal/10 to-royal/[0.02] p-6 shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-widest text-royal/70">{{ __('earnings.month') }}</span>
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-royal/15 text-royal">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                    </div>
                </div>
                <div class="mt-4 font-display text-3xl font-bold tabular-nums text-content">{{ $this->formatSum($this->earnings['month']) }}</div>
            </div>
        </div>
    @endif
</div>
