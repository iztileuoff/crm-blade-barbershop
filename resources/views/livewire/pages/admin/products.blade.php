<?php

use App\Models\Product;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
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
    public function products()
    {
        return Product::orderBy('name')->get();
    }

    #[Computed]
    public function totalProducts(): int
    {
        return Product::count();
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
    }

    public function adjustStock(int $id, int $delta): void
    {
        $product = Product::findOrFail($id);
        $newStock = max(0, $product->stock + $delta);
        $product->update(['stock' => $newStock]);
        unset($this->products);
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

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-extrabold tracking-tight text-white">Товары</h1>
            <p class="mt-1 text-sm text-white/40">Складской учёт — шампуни, воски, масла · Всего: <span class="font-bold text-white/70">{{ $this->totalProducts }}</span></p>
        </div>
        <button type="button" wire:click="openCreate"
                class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-amber-400 to-amber-600 px-5 py-2.5 text-sm font-bold text-black shadow-lg shadow-amber-500/20 transition-all hover:scale-[1.02] hover:shadow-amber-500/30 active:scale-[0.98]">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
            Добавить товар
        </button>
    </div>

    @if ($showForm)
        <div class="mb-8 overflow-hidden rounded-2xl border border-white/[0.06] bg-white/[0.03] shadow-xl backdrop-blur-md">
            <div class="border-b border-white/[0.06] bg-white/[0.03] px-6 py-4">
                <h3 class="text-sm font-bold text-white">{{ $editingId ? 'Редактировать товар' : 'Новый товар' }}</h3>
            </div>
            <form wire:submit="save" class="p-6">
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="lg:col-span-2">
                        <label class="mb-1.5 block text-xs font-semibold text-white/50">Название</label>
                        <input type="text" wire:model="name" placeholder="Шампунь, воск, масло..."
                               class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white placeholder-white/20 outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20">
                        @error('name') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-white/50">Цена продажи (сум)</label>
                        <input type="number" wire:model="selling_price" min="0" placeholder="0"
                               class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20">
                        @error('selling_price') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-semibold text-white/50">Остаток (шт)</label>
                        <input type="number" wire:model="stock" min="0" placeholder="0"
                               class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20">
                        @error('stock') <p class="mt-1.5 text-xs text-rose-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-center pt-5">
                        <label class="relative inline-flex cursor-pointer items-center">
                            <input type="checkbox" wire:model="is_active" class="peer sr-only">
                            <div class="h-6 w-11 rounded-full bg-white/10 transition-colors peer-checked:bg-amber-500 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-all peer-checked:after:translate-x-full"></div>
                            <span class="ml-3 text-sm font-medium text-white/70">Активен</span>
                        </label>
                    </div>
                </div>
                <div class="mt-8 flex items-center justify-end gap-3 border-t border-white/[0.06] pt-6">
                    <button type="button" wire:click="cancel"
                            class="rounded-xl border border-white/[0.08] px-5 py-2.5 text-sm font-bold text-white/60 transition hover:bg-white/[0.06] hover:text-white">
                        Отмена
                    </button>
                    <button type="submit"
                            class="rounded-xl bg-amber-500 px-6 py-2.5 text-sm font-bold text-black transition-all hover:bg-amber-400 active:scale-[0.98]">
                        {{ $editingId ? 'Сохранить' : 'Добавить' }}
                    </button>
                </div>
            </form>
        </div>
    @endif

    <div class="overflow-hidden rounded-2xl border border-white/[0.06] bg-white/[0.03] shadow-xl backdrop-blur-md">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-white/[0.06] bg-white/[0.03] text-xs font-bold uppercase tracking-wider text-white/30">
                        <th class="px-6 py-4">Название</th>
                        <th class="px-6 py-4 text-center">Остаток</th>
                        <th class="px-6 py-4 text-right">Цена</th>
                        <th class="hidden px-6 py-4 sm:table-cell">Статус</th>
                        <th class="px-6 py-4 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/[0.04]">
                    @forelse ($this->products as $product)
                        <tr class="transition-colors hover:bg-white/[0.02]">
                            <td class="px-6 py-4">
                                <div class="font-bold text-white">{{ $product->name }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-center gap-2">
                                    <button type="button" wire:click="adjustStock({{ $product->id }}, -1)"
                                            class="flex h-7 w-7 items-center justify-center rounded-lg border border-white/[0.06] text-white/40 transition hover:border-rose-500/30 hover:text-rose-400">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" /></svg>
                                    </button>
                                    <span @class([
                                        'min-w-[2.5rem] text-center text-sm font-extrabold tabular-nums',
                                        'text-emerald-400' => $product->stock > 5,
                                        'text-amber-400' => $product->stock > 0 && $product->stock <= 5,
                                        'text-rose-400' => $product->stock === 0,
                                    ])>{{ $product->stock }}</span>
                                    <button type="button" wire:click="adjustStock({{ $product->id }}, 1)"
                                            class="flex h-7 w-7 items-center justify-center rounded-lg border border-white/[0.06] text-white/40 transition hover:border-emerald-500/30 hover:text-emerald-400">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                    </button>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <span class="font-bold text-amber-400 tabular-nums">{{ $product->formattedPrice }}</span>
                            </td>
                            <td class="hidden px-6 py-4 sm:table-cell">
                                @if ($product->is_active)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-bold text-emerald-400">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                                        Активен
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-2.5 py-1 text-xs font-bold text-white/30">
                                        <span class="h-1.5 w-1.5 rounded-full bg-white/30"></span>
                                        Неактивен
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" wire:click="edit({{ $product->id }})"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-white/[0.06] text-white/40 transition hover:border-white/10 hover:text-white">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" /></svg>
                                    </button>
                                    <button type="button" wire:click="delete({{ $product->id }})"
                                            wire:confirm="Удалить товар «{{ $product->name }}»?"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-white/[0.06] text-rose-500/50 transition hover:border-rose-500/20 hover:text-rose-500">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-white/20">Товары не добавлены</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
