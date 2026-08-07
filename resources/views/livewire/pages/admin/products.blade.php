<?php

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.app')]
class extends Component
{
    use WithPagination;

    private const STOCK_FILTERS = ['all', 'low', 'none'];

    /** Разумный потолок разовой приёмки: защита от опечатки в поле количества. */
    private const MAX_RECEIVE = 9999;

    public string $search = '';

    public string $stockFilter = 'all';

    /** @var array<int, int|string|null> количество к приёмке по id товара */
    public array $receiveQty = [];

    public ?int $editingId = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|integer|min:0')]
    public int $selling_price = 0;

    #[Validate('required|integer|min:0')]
    public int $stock = 0;

    #[Validate('boolean')]
    public bool $is_active = true;

    public bool $showForm = false;

    #[Computed]
    public function products(): LengthAwarePaginator
    {
        return Product::query()
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where('name', 'like', '%'.trim($this->search).'%'))
            ->when($this->stockFilter === 'low', fn (Builder $q) => $q->whereBetween('stock', [1, Product::LOW_STOCK]))
            ->when($this->stockFilter === 'none', fn (Builder $q) => $q->where('stock', '<=', 0))
            ->orderBy('name')
            ->paginate(25);
    }

    #[Computed]
    public function totalProducts(): int
    {
        return Product::count();
    }

    #[Computed]
    public function lowStockCount(): int
    {
        return Product::whereBetween('stock', [1, Product::LOW_STOCK])->count();
    }

    #[Computed]
    public function outOfStockCount(): int
    {
        return Product::where('stock', '<=', 0)->count();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setStockFilter(string $filter): void
    {
        $this->stockFilter = in_array($filter, self::STOCK_FILTERS, true) ? $filter : 'all';
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $product = Product::findOrFail($id);
        $this->editingId = $product->id;
        $this->name = $product->name;
        $this->selling_price = $product->selling_price;
        $this->stock = $product->stock;
        $this->is_active = $product->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();

        $payload = [
            'name' => $this->name,
            'selling_price' => $this->selling_price,
            'stock' => $this->stock,
            'is_active' => $this->is_active,
        ];

        if ($this->editingId) {
            Product::findOrFail($this->editingId)->update($payload);
        } else {
            Product::create($payload);
        }

        unset($this->products);
        $this->showForm = false;
        $this->resetForm();

        $this->dispatch('saved');
    }

    public function adjustStock(int $id, int $delta): void
    {
        $product = Product::findOrFail($id);
        $newStock = max(0, $product->stock + $delta);
        $product->update(['stock' => $newStock]);

        unset($this->products, $this->lowStockCount, $this->outOfStockCount);

        $this->dispatch('saved');
    }

    /**
     * Приёмка партии одной операцией: степпером −1/+1 партия в 30 бутылок
     * стоила 30 запросов, а перезапись остатка в форме молча затирала
     * параллельные продажи. Здесь остаток двигается дельтой.
     */
    public function receiveStock(int $id): void
    {
        $quantity = (int) ($this->receiveQty[$id] ?? 0);

        if ($quantity < 1 || $quantity > self::MAX_RECEIVE) {
            $this->addError('receiveQty.'.$id, __('products.err_receive_quantity', ['max' => self::MAX_RECEIVE]));

            return;
        }

        $this->resetErrorBag('receiveQty.'.$id);
        $this->adjustStock($id, $quantity);

        unset($this->receiveQty[$id]);
    }

    public function delete(int $id): void
    {
        Product::findOrFail($id)->delete();
        unset($this->products);
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'selling_price', 'stock', 'is_active']);
        $this->is_active = true;
        $this->resetErrorBag();
    }
}; ?>

<x-slot:title>{{ __('products.page_title') }}</x-slot:title>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('products.title') }}</h1>
            <p class="mt-1 text-sm text-content/40">{{ __('products.subtitle') }} · {{ __('common.total_count') }}: <span class="font-bold text-content/70">{{ $this->totalProducts }}</span></p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative">
                <svg class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-content-subtle" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('products.search_placeholder') }}"
                       aria-label="{{ __('products.search_placeholder') }}"
                       class="w-56 rounded-xl border border-content/[0.08] bg-content/[0.04] py-2.5 pl-10 pr-4 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
            </div>
            <button type="button" wire:click="openCreate"
                    class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-brass to-brass px-5 py-2.5 text-sm font-bold text-black shadow-lg shadow-brass/20 transition-all hover:scale-[1.02] hover:shadow-brass/30 active:scale-[0.98]">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ __('products.add') }}
            </button>
        </div>
    </div>

    {{-- Фильтр остатков: «что заканчивается» — главный вопрос к этой странице --}}
    <div class="mb-6 flex flex-wrap gap-2">
        @foreach ([
            'all' => __('common.all').' ('.$this->totalProducts.')',
            'low' => __('products.filter_low').' ('.$this->lowStockCount.')',
            'none' => __('products.filter_none').' ('.$this->outOfStockCount.')',
        ] as $key => $label)
            <button type="button" wire:click="setStockFilter('{{ $key }}')" wire:key="stock-filter-{{ $key }}"
                    role="tab" aria-selected="{{ $stockFilter === $key ? 'true' : 'false' }}"
                    @class([
                        'rounded-xl border px-4 py-2 text-sm font-bold transition focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-brass/40',
                        'border-brass bg-brass text-on-brass' => $stockFilter === $key,
                        'border-content/[0.08] bg-content/[0.04] text-content/50 hover:bg-content/[0.08] hover:text-content' => $stockFilter !== $key,
                    ])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($showForm)
        <div class="mb-8 overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
            <div class="border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
                <h3 class="text-sm font-bold text-content">{{ $editingId ? __('products.edit_title') : __('products.create_title') }}</h3>
            </div>
            <form wire:submit="save" class="p-6">
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="lg:col-span-2">
                        <label for="product-name" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('common.title_field') }}</label>
                        <input type="text" id="product-name" wire:model="name" placeholder="{{ __('products.name_placeholder') }}"
                               x-data x-init="$nextTick(() => $el.focus())"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('name') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="product-price" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('products.selling_price') }} ({{ __('common.currency') }})</label>
                        <input type="number" id="product-price" wire:model="selling_price" min="0" placeholder="0"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('selling_price') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="product-stock" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('products.stock') }} ({{ __('common.pieces') }})</label>
                        <input type="number" id="product-stock" wire:model="stock" min="0" placeholder="0"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('stock') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-center pt-5">
                        <label for="product-is-active" class="relative inline-flex cursor-pointer items-center">
                            <input type="checkbox" id="product-is-active" wire:model="is_active" class="peer sr-only">
                            <div class="h-6 w-11 rounded-full bg-content/10 transition-colors peer-checked:bg-brass peer-focus-visible:ring-2 peer-focus-visible:ring-brass/40 peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-surface after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-content after:transition-all peer-checked:after:translate-x-full"></div>
                            <span class="ml-3 text-sm font-medium text-content/70">{{ __('common.active') }}</span>
                        </label>
                    </div>
                </div>
                <div class="mt-8 flex items-center justify-end gap-3 border-t border-content/[0.06] pt-6">
                    <button type="button" wire:click="cancel"
                            class="rounded-xl border border-content/[0.08] px-5 py-2.5 text-sm font-bold text-content/60 transition hover:bg-content/[0.06] hover:text-content">
                        {{ __('common.cancel') }}
                    </button>
                    <x-submit-button>
                        {{ $editingId ? __('common.save') : __('common.add') }}
                    </x-submit-button>
                </div>
            </form>
        </div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content-muted">
                        <th class="px-6 py-4">{{ __('common.title_field') }}</th>
                        <th class="px-6 py-4 text-center">{{ __('products.stock') }}</th>
                        <th class="px-6 py-4 text-right">{{ __('common.price') }}</th>
                        <th class="hidden px-6 py-4 sm:table-cell">{{ __('common.status') }}</th>
                        <th class="px-6 py-4 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-content/[0.04]">
                    @forelse ($this->products as $product)
                        @php($stockTarget = 'adjustStock('.$product->id.', -1),adjustStock('.$product->id.', 1),receiveStock('.$product->id.')')
                        <tr class="transition-colors hover:bg-content/[0.02]" wire:key="product-{{ $product->id }}">
                            <td class="px-6 py-4">
                                <div class="font-bold text-content">{{ $product->name }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-center gap-2">
                                    <button type="button" wire:click="adjustStock({{ $product->id }}, -1)"
                                            wire:loading.attr="disabled" wire:target="{{ $stockTarget }}"
                                            title="{{ __('products.decrease_stock') }}" aria-label="{{ __('products.decrease_stock') }}"
                                            class="flex h-7 w-7 items-center justify-center rounded-lg border border-content/[0.06] text-content/40 transition hover:border-danger/30 hover:text-danger focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-danger/40 disabled:opacity-40">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" /></svg>
                                    </button>
                                    <span @class([
                                        'min-w-[2.5rem] text-center text-sm font-extrabold tabular-nums',
                                        'text-success' => $product->stock > \App\Models\Product::LOW_STOCK,
                                        'text-brass-ink' => $product->stock > 0 && $product->stock <= \App\Models\Product::LOW_STOCK,
                                        'text-danger' => $product->stock <= 0,
                                    ])>{{ $product->stock }}</span>
                                    <button type="button" wire:click="adjustStock({{ $product->id }}, 1)"
                                            wire:loading.attr="disabled" wire:target="{{ $stockTarget }}"
                                            title="{{ __('products.increase_stock') }}" aria-label="{{ __('products.increase_stock') }}"
                                            class="flex h-7 w-7 items-center justify-center rounded-lg border border-content/[0.06] text-content/40 transition hover:border-success/30 hover:text-success focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-success/40 disabled:opacity-40">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                    </button>
                                </div>

                                {{-- Приёмка партии: одна операция вместо тридцати тапов --}}
                                <form wire:submit="receiveStock({{ $product->id }})" class="mt-2 flex items-center justify-center gap-1.5">
                                    <input type="number" min="1" max="9999" inputmode="numeric"
                                           wire:model="receiveQty.{{ $product->id }}"
                                           placeholder="{{ __('products.receive_placeholder') }}"
                                           aria-label="{{ __('products.receive') }}"
                                           class="w-16 rounded-lg border border-content/[0.08] bg-content/[0.04] px-2 py-1.5 text-center text-xs text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                                    <button type="submit" title="{{ __('products.receive') }}"
                                            wire:loading.attr="disabled" wire:target="{{ $stockTarget }}"
                                            class="flex h-7 items-center gap-1 rounded-lg border border-success/20 bg-success/10 px-2 text-[10px] font-bold text-success transition hover:bg-success hover:text-black disabled:opacity-40">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        {{ __('products.receive') }}
                                    </button>
                                </form>
                                @error('receiveQty.'.$product->id)
                                    <p class="mt-1 text-center text-[10px] text-danger">{{ $message }}</p>
                                @enderror
                            </td>
                            <td class="px-6 py-4 text-right">
                                <span class="font-bold text-brass-ink tabular-nums">{{ $product->formattedPrice }}</span>
                            </td>
                            <td class="hidden px-6 py-4 sm:table-cell">
                                @if ($product->is_active)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-xs font-bold text-success">
                                        <span class="h-1.5 w-1.5 rounded-full bg-success"></span>
                                        {{ __('common.active') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-content/10 px-2.5 py-1 text-xs font-bold text-content-muted">
                                        <span class="h-1.5 w-1.5 rounded-full bg-content/30"></span>
                                        {{ __('common.inactive') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" wire:click="edit({{ $product->id }})"
                                            title="{{ __('common.edit') }}" aria-label="{{ __('common.edit') }}"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-content/[0.06] text-content/40 transition hover:border-content/10 hover:text-content focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-brass/40">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" /></svg>
                                    </button>
                                    <button type="button" wire:click="delete({{ $product->id }})"
                                            wire:confirm="{{ __('products.delete_confirm', ['name' => $product->name]) }}"
                                            title="{{ __('common.delete') }}" aria-label="{{ __('common.delete') }}"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-content/[0.06] text-danger/50 transition hover:border-danger/20 hover:text-danger focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-danger/40">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-content-muted">
                                {{ trim($search) !== '' || $stockFilter !== 'all' ? __('common.nothing_found') : __('products.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">
        {{ $this->products->links() }}
    </div>
</div>
