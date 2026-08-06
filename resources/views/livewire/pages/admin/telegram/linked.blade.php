<?php

use App\Enums\Role;
use App\Models\Client;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.app')]
class extends Component
{
    use WithPagination;

    private const FILTERS = ['all', 'clients', 'barbers'];

    public string $search = '';

    public string $filter = 'all';

    /**
     * Привязки постранично, одним UNION-запросом.
     *
     * Раньше обе таблицы материализовались целиком, гидрировались в модели и
     * склеивались в PHP: страница деградировала линейно с ростом привязок.
     */
    #[Computed]
    public function linked(): LengthAwarePaginator
    {
        $query = match ($this->filter) {
            'clients' => $this->clientRows(),
            'barbers' => $this->barberRows(),
            default => $this->clientRows()->unionAll($this->barberRows()),
        };

        return $query->orderBy('name')->paginate(25);
    }

    private function clientRows(): Builder
    {
        return $this->applySearch(
            DB::table('clients')
                ->selectRaw("'client' as type, id, name, phone, telegram_chat_id")
                ->whereNotNull('telegram_chat_id'),
        );
    }

    private function barberRows(): Builder
    {
        return $this->applySearch(
            DB::table('users')
                ->selectRaw("'barber' as type, id, name, phone, telegram_chat_id")
                ->where('role', Role::BARBER->value)
                ->whereNotNull('telegram_chat_id'),
        );
    }

    /**
     * Поиск по имени или телефону в том виде, в каком телефон показан в UI.
     */
    private function applySearch(Builder $query): Builder
    {
        $term = trim($this->search);

        if ($term === '') {
            return $query;
        }

        $digits = Client::searchDigits($term);

        return $query->where(function (Builder $q) use ($term, $digits): void {
            $q->where('name', 'like', '%'.$term.'%');

            if ($digits !== null) {
                $q->orWhere('phone', 'like', '%'.$digits.'%');
            }
        });
    }

    /** Счётчики — отдельными count(), а не из загруженной коллекции. */
    #[Computed]
    public function clientsCount(): int
    {
        return Client::whereNotNull('telegram_chat_id')->count();
    }

    #[Computed]
    public function barbersCount(): int
    {
        return User::where('role', Role::BARBER)->whereNotNull('telegram_chat_id')->count();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';
        $this->resetPage();
    }

    public function unlinkClient(int $id): void
    {
        Client::whereKey($id)->update(['telegram_chat_id' => null]);
        unset($this->linked, $this->clientsCount);
    }

    public function unlinkBarber(int $id): void
    {
        User::whereKey($id)->update(['telegram_chat_id' => null]);
        unset($this->linked, $this->barbersCount);
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('telegram.linked_title') }}</h1>
            <p class="mt-1 text-sm text-content/40">{{ __('telegram.linked_subtitle') }} · {{ __('common.total_count') }}: <span class="font-bold text-content/70">{{ $this->clientsCount + $this->barbersCount }}</span></p>
        </div>
        <div class="relative">
            <svg class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-content/20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('telegram.search_placeholder') }}"
                   class="w-64 rounded-xl border border-content/[0.08] bg-content/[0.04] py-2.5 pl-10 pr-4 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
        </div>
    </div>

    {{-- Фильтр по типу привязки --}}
    <div class="mb-6 flex flex-wrap gap-2">
        @foreach ([
            'all' => __('common.all').' ('.($this->clientsCount + $this->barbersCount).')',
            'clients' => __('nav.clients').' ('.$this->clientsCount.')',
            'barbers' => __('nav.barbers').' ('.$this->barbersCount.')',
        ] as $key => $label)
            <button type="button" wire:click="setFilter('{{ $key }}')" wire:key="filter-{{ $key }}"
                    @class([
                        'rounded-xl border px-4 py-2 text-sm font-bold transition',
                        'border-brass bg-brass text-on-brass' => $filter === $key,
                        'border-content/[0.08] bg-content/[0.04] text-content/50 hover:bg-content/[0.08] hover:text-content' => $filter !== $key,
                    ])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content/30">
                        <th class="px-6 py-4">{{ __('common.name') }}</th>
                        <th class="px-6 py-4">{{ __('common.type') }}</th>
                        <th class="hidden px-6 py-4 sm:table-cell">{{ __('common.phone') }}</th>
                        <th class="hidden px-6 py-4 sm:table-cell">{{ __('telegram.col_chat_id') }}</th>
                        <th class="px-6 py-4 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-content/[0.04]">
                    @forelse ($this->linked as $row)
                        @php($isBarber = $row->type === 'barber')
                        <tr class="transition-colors hover:bg-content/[0.02]" wire:key="link-{{ $row->type }}-{{ $row->id }}">
                            <td class="px-6 py-4 font-bold text-content">{{ $row->name }}</td>
                            <td class="px-6 py-4">
                                @if ($isBarber)
                                    <span class="inline-flex items-center rounded-full bg-brass/10 px-2.5 py-1 text-xs font-bold text-brass-ink">{{ __('common.barber') }}</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-content/[0.06] px-2.5 py-1 text-xs font-bold text-content/60">{{ __('common.client') }}</span>
                                @endif
                            </td>
                            <td class="hidden whitespace-nowrap px-6 py-4 text-brass-ink/60 sm:table-cell">{{ \App\Models\Client::formatPhone((string) $row->phone) }}</td>
                            <td class="hidden whitespace-nowrap px-6 py-4 font-mono text-content/40 sm:table-cell">{{ $row->telegram_chat_id }}</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end">
                                    <button type="button"
                                            wire:click="{{ $isBarber ? 'unlinkBarber' : 'unlinkClient' }}({{ $row->id }})"
                                            wire:confirm="{{ __('telegram.unlink_confirm', ['name' => $row->name]) }}"
                                            class="flex items-center gap-1.5 rounded-lg border border-content/[0.06] px-3 py-2 text-xs font-bold text-danger/60 transition hover:border-danger/20 hover:text-danger">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5 21 3m0 0h-5.25M21 3v5.25M10.5 6H6.75A2.25 2.25 0 0 0 4.5 8.25v9a2.25 2.25 0 0 0 2.25 2.25h9a2.25 2.25 0 0 0 2.25-2.25V13.5" /></svg>
                                        {{ __('telegram.unlink') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-content/20">
                                {{ trim($search) !== '' ? __('common.nothing_found') : __('telegram.empty_linked') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">
        {{ $this->linked->links() }}
    </div>
</div>
