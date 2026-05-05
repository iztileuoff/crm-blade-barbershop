<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    public string $today = '';

    public function mount(): void
    {
        $this->today = Carbon::now('Asia/Tashkent')->translatedFormat('d F Y, l');
    }

    #[Computed]
    public function appointments(): Collection
    {
        return Appointment::query()
            ->with(['barber', 'service'])
            ->whereDate('starts_at', today())
            ->get();
    }

    #[Computed]
    public function totalAppointments(): int
    {
        return $this->appointments->count();
    }

    #[Computed]
    public function confirmedAmount(): int
    {
        return (int) $this->appointments
            ->whereIn('status', [AppointmentStatus::Completed, AppointmentStatus::Confirmed])
            ->sum(fn ($appointment) => (int) ($appointment->price ?? $appointment->barber?->price ?? 0));
    }

    #[Computed]
    public function pendingCount(): int
    {
        return $this->appointments
            ->where('status', AppointmentStatus::Pending)
            ->count();
    }

    #[Computed]
    public function completedCount(): int
    {
        return $this->appointments
            ->where('status', AppointmentStatus::Completed)
            ->count();
    }

    #[Computed]
    public function barberStats(): Collection
    {
        $byBarber = $this->appointments->groupBy('barber_id');

        return Barber::active()
            ->orderBy('name')
            ->get()
            ->map(function (Barber $barber) use ($byBarber) {
                $items = $byBarber->get($barber->id, collect());

                $revenue = (int) $items
                    ->whereIn('status', [AppointmentStatus::Completed, AppointmentStatus::Confirmed])
                    ->sum(fn ($appointment) => (int) ($appointment->price ?? $barber->price ?? 0));

                return (object) [
                    'id' => $barber->id,
                    'name' => $barber->name,
                    'photoUrl' => $barber->photoUrl,
                    'count' => $items->count(),
                    'revenue' => $revenue,
                    'formattedRevenue' => number_format($revenue, 0, '.', ' ').' сум',
                ];
            });
    }

    public function formatSum(int $value): string
    {
        return number_format($value, 0, '.', ' ').' сум';
    }
}; ?>

<div class="animate-fade-in-up" wire:poll.60s>
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-extrabold tracking-tight text-white">Касса</h1>
            <p class="mt-1 text-sm text-white/40">Сводка за сегодня — {{ $today }}</p>
        </div>
        <div class="flex items-center gap-2 rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-3 py-1.5 text-xs font-bold text-emerald-400">
            <span class="relative flex h-2 w-2">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
            </span>
            Обновление каждые 60 сек
        </div>
    </div>

    {{-- Top Stats --}}
    <div class="mb-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        {{-- Total appointments --}}
        <div class="overflow-hidden rounded-2xl border border-white/[0.06] bg-gradient-to-br from-white/[0.04] to-white/[0.01] p-6 shadow-xl backdrop-blur-md">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-widest text-white/40">Записей сегодня</span>
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10 text-amber-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                </div>
            </div>
            <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->totalAppointments }}</div>
            <div class="mt-1 text-xs text-white/30">Все записи на день</div>
        </div>

        {{-- Confirmed amount --}}
        <div class="overflow-hidden rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-500/10 to-emerald-500/[0.02] p-6 shadow-xl backdrop-blur-md">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-widest text-emerald-400/70">Сумма в кассе</span>
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/15 text-emerald-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                </div>
            </div>
            <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->formatSum($this->confirmedAmount) }}</div>
            <div class="mt-1 text-xs text-emerald-400/60">Подтверждённые и завершённые</div>
        </div>

        {{-- Pending --}}
        <div class="overflow-hidden rounded-2xl border border-amber-500/20 bg-gradient-to-br from-amber-500/10 to-amber-500/[0.02] p-6 shadow-xl backdrop-blur-md">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-widest text-amber-400/70">Ожидают</span>
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/15 text-amber-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                </div>
            </div>
            <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->pendingCount }}</div>
            <div class="mt-1 text-xs text-amber-400/60">Требуют подтверждения</div>
        </div>

        {{-- Completed --}}
        <div class="overflow-hidden rounded-2xl border border-white/[0.06] bg-gradient-to-br from-white/[0.04] to-white/[0.01] p-6 shadow-xl backdrop-blur-md">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-widest text-white/40">Завершено</span>
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/10 text-blue-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                </div>
            </div>
            <div class="mt-4 text-4xl font-extrabold text-white">{{ $this->completedCount }}</div>
            <div class="mt-1 text-xs text-white/30">Закрытые визиты</div>
        </div>
    </div>

    {{-- Barber Performance --}}
    <div class="overflow-hidden rounded-2xl border border-white/[0.06] bg-white/[0.03] shadow-xl backdrop-blur-md">
        <div class="flex items-center justify-between border-b border-white/[0.06] bg-white/[0.03] px-6 py-4">
            <div>
                <h3 class="text-sm font-bold text-white">Производительность мастеров</h3>
                <p class="mt-0.5 text-xs text-white/30">Записи и выручка за сегодня</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-white/[0.06] bg-white/[0.03] text-xs font-bold uppercase tracking-wider text-white/30">
                        <th class="px-6 py-4">Имя</th>
                        <th class="px-6 py-4 text-center">Записей сегодня</th>
                        <th class="px-6 py-4 text-right">Выручка</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/[0.04]">
                    @forelse ($this->barberStats as $stat)
                        <tr class="transition-colors hover:bg-white/[0.02]">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    @if ($stat->photoUrl)
                                        <img src="{{ $stat->photoUrl }}" class="h-9 w-9 rounded-full object-cover ring-1 ring-white/10">
                                    @else
                                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-white/[0.06] text-xs font-bold text-white/40">
                                            {{ mb_substr($stat->name, 0, 1) }}
                                        </div>
                                    @endif
                                    <div class="font-bold text-white">{{ $stat->name }}</div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span @class([
                                    'inline-flex min-w-[2.5rem] items-center justify-center rounded-full px-3 py-1 text-xs font-bold',
                                    'bg-amber-500/10 text-amber-400' => $stat->count > 0,
                                    'bg-white/[0.04] text-white/30' => $stat->count === 0,
                                ])>
                                    {{ $stat->count }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <span @class([
                                    'font-extrabold tabular-nums',
                                    'text-emerald-400' => $stat->revenue > 0,
                                    'text-white/30' => $stat->revenue === 0,
                                ])>
                                    {{ $stat->formattedRevenue }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-6 py-12 text-center text-white/20">Активные мастера не найдены</td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($this->barberStats->isNotEmpty())
                    <tfoot>
                        <tr class="border-t border-white/[0.06] bg-white/[0.03] text-xs font-bold uppercase tracking-wider text-white/40">
                            <td class="px-6 py-4">Итого</td>
                            <td class="px-6 py-4 text-center text-white">{{ $this->barberStats->sum('count') }}</td>
                            <td class="px-6 py-4 text-right text-emerald-400">{{ $this->formatSum((int) $this->barberStats->sum('revenue')) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
