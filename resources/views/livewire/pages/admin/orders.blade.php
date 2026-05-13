<?php

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    public string $date = '';

    public bool $showForm = false;

    public ?int $client_id = null;

    public string $clientSearch = '';

    public string $note = '';

    /** @var array<int, array{product_id: int, quantity: int, price: int, name: string}> */
    public array $cartItems = [];

    public function mount(): void
    {
        $this->date = Carbon::now('Asia/Tashkent')->toDateString();
    }

    #[Computed]
    public function orders(): Collection
    {
        return Order::query()
            ->with(['client', 'items.product'])
            ->whereDate('created_at', $this->date)
            ->latest()
            ->get();
    }

    #[Computed]
    public function filteredClients(): Collection
    {
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

    #[Computed]
    public function availableProducts(): Collection
    {
        return Product::active()->where('stock', '>', 0)->orderBy('name')->get();
    }

    #[Computed]
    public function cartTotal(): int
    {
        return array_sum(array_map(fn ($item) => $item['quantity'] * $item['price'], $this->cartItems));
    }

    #[Computed]
    public function todayTotal(): int
    {
        return (int) $this->orders->sum('total_price');
    }

    public function selectClient(int $id, string $label): void
    {
        $this->client_id = $id;
        $this->clientSearch = $label;
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function addToCart(int $productId): void
    {
        $product = Product::find($productId);

        if (! $product || $product->stock <= 0) {
            return;
        }

        foreach ($this->cartItems as $key => $item) {
            if ($item['product_id'] === $productId) {
                $maxQty = $product->stock;
                if ($this->cartItems[$key]['quantity'] < $maxQty) {
                    $this->cartItems[$key]['quantity']++;
                }

                return;
            }
        }

        $this->cartItems[] = [
            'product_id' => $productId,
            'name' => $product->name,
            'price' => $product->selling_price,
            'quantity' => 1,
        ];
    }

    public function removeFromCart(int $index): void
    {
        unset($this->cartItems[$index]);
        $this->cartItems = array_values($this->cartItems);
    }

    public function updateQuantity(int $index, int $quantity): void
    {
        if ($quantity <= 0) {
            $this->removeFromCart($index);

            return;
        }

        $productId = $this->cartItems[$index]['product_id'] ?? null;
        $maxQty = $productId ? (Product::find($productId)?->stock ?? $quantity) : $quantity;

        $this->cartItems[$index]['quantity'] = min($quantity, $maxQty);
    }

    public function save(): void
    {
        if (empty($this->cartItems)) {
            $this->addError('cart', 'Добавьте хотя бы один товар.');

            return;
        }

        $total = $this->cartTotal;

        $order = Order::create([
            'client_id' => $this->client_id,
            'total_price' => $total,
            'note' => $this->note ?: null,
        ]);

        foreach ($this->cartItems as $item) {
            $product = Product::find($item['product_id']);

            if (! $product) {
                continue;
            }

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => $item['quantity'],
                'price_at_sale' => $item['price'],
            ]);

            $product->decrement('stock', $item['quantity']);
        }

        unset($this->orders, $this->availableProducts);
        $this->showForm = false;
        $this->resetForm();
    }

    public function deleteOrder(int $id): void
    {
        $order = Order::with('items')->findOrFail($id);

        foreach ($order->items as $item) {
            $item->product?->increment('stock', $item->quantity);
        }

        $order->delete();
        unset($this->orders, $this->availableProducts);
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['client_id', 'clientSearch', 'note', 'cartItems']);
        $this->resetErrorBag();
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-extrabold tracking-tight text-white">Продажи товаров</h1>
            <p class="mt-1 text-sm text-white/40">Касса — розничные продажи</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <input
                type="date"
                wire:model.live="date"
                class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm text-white shadow-sm transition-colors focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 dark:[color-scheme:dark]"
            >
            <button type="button" wire:click="openCreate"
                    class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-amber-400 to-amber-600 px-5 py-2.5 text-sm font-bold text-black shadow-lg shadow-amber-500/20 transition-all hover:scale-[1.02] hover:shadow-amber-500/30 active:scale-[0.98]">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Новая продажа
            </button>
        </div>
    </div>

    {{-- Day total --}}
    <div class="mb-8 overflow-hidden rounded-2xl border border-emerald-500/20 bg-gradient-to-br from-emerald-500/10 to-emerald-500/[0.02] p-6 shadow-xl backdrop-blur-md">
        <div class="flex items-center justify-between">
            <div>
                <span class="text-xs font-bold uppercase tracking-widest text-emerald-400/70">Выручка за день</span>
                <div class="mt-2 text-4xl font-extrabold text-white tabular-nums">
                    {{ number_format($this->todayTotal, 0, '.', ' ') }} сум
                </div>
                <div class="mt-1 text-xs text-emerald-400/60">{{ $this->orders->count() }} продаж{{ match(true) { $this->orders->count() % 10 === 1 && $this->orders->count() % 100 !== 11 => 'а', in_array($this->orders->count() % 10, [2,3,4]) && !in_array($this->orders->count() % 100, [12,13,14]) => 'и', default => '' } }}</div>
            </div>
            <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-500/15 text-emerald-400">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z" /></svg>
            </div>
        </div>
    </div>

    {{-- New order form --}}
    @if ($showForm)
        <div class="mb-8 overflow-hidden rounded-2xl border border-white/[0.06] bg-white/[0.03] shadow-xl backdrop-blur-md">
            <div class="border-b border-white/[0.06] bg-white/[0.03] px-6 py-4">
                <h3 class="text-sm font-bold text-white">Новая продажа</h3>
            </div>
            <div class="grid gap-0 lg:grid-cols-2">
                {{-- Left: product selector --}}
                <div class="border-b border-white/[0.06] p-6 lg:border-b-0 lg:border-r">
                    <p class="mb-4 text-xs font-semibold uppercase tracking-wider text-white/40">Выберите товары</p>
                    @if ($this->availableProducts->isEmpty())
                        <p class="text-sm text-white/30">Нет товаров в наличии.</p>
                    @else
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($this->availableProducts as $product)
                                <button type="button" wire:click="addToCart({{ $product->id }})"
                                        class="group flex items-center justify-between rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-3 text-left transition hover:border-amber-500/30 hover:bg-amber-500/[0.05]">
                                    <div>
                                        <div class="text-sm font-bold text-white">{{ $product->name }}</div>
                                        <div class="text-xs text-amber-400/70">{{ $product->formattedPrice }}</div>
                                    </div>
                                    <div class="ml-3 shrink-0 text-right">
                                        <span @class([
                                            'text-xs font-bold tabular-nums',
                                            'text-emerald-400' => $product->stock > 5,
                                            'text-amber-400' => $product->stock <= 5,
                                        ])>{{ $product->stock }} шт</span>
                                        <div class="mt-0.5 flex h-6 w-6 items-center justify-center rounded-lg bg-amber-500/10 text-amber-400 opacity-0 transition group-hover:opacity-100">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        </div>
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Right: cart + client + save --}}
                <div class="p-6">
                    <p class="mb-4 text-xs font-semibold uppercase tracking-wider text-white/40">Корзина</p>

                    @error('cart')
                        <p class="mb-4 rounded-xl bg-rose-500/10 px-4 py-3 text-xs font-bold text-rose-400">{{ $message }}</p>
                    @enderror

                    @if (empty($cartItems))
                        <p class="mb-6 text-sm text-white/20">Товары не добавлены</p>
                    @else
                        <div class="mb-6 space-y-2">
                            @foreach ($cartItems as $index => $item)
                                <div class="flex items-center gap-3 rounded-xl border border-white/[0.06] bg-white/[0.02] px-4 py-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-bold text-white">{{ $item['name'] }}</div>
                                        <div class="text-xs text-white/40">{{ number_format($item['price'], 0, '.', ' ') }} сум × {{ $item['quantity'] }} = <span class="font-bold text-amber-400">{{ number_format($item['price'] * $item['quantity'], 0, '.', ' ') }} сум</span></div>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1.5">
                                        <button type="button" wire:click="updateQuantity({{ $index }}, {{ $item['quantity'] - 1 }})"
                                                class="flex h-7 w-7 items-center justify-center rounded-lg border border-white/[0.06] text-white/40 transition hover:text-rose-400">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" /></svg>
                                        </button>
                                        <span class="w-6 text-center text-sm font-bold text-white tabular-nums">{{ $item['quantity'] }}</span>
                                        <button type="button" wire:click="updateQuantity({{ $index }}, {{ $item['quantity'] + 1 }})"
                                                class="flex h-7 w-7 items-center justify-center rounded-lg border border-white/[0.06] text-white/40 transition hover:text-emerald-400">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        </button>
                                    </div>
                                    <button type="button" wire:click="removeFromCart({{ $index }})" class="text-white/20 transition hover:text-rose-500">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        <div class="mb-6 flex items-center justify-between rounded-xl border border-amber-500/20 bg-amber-500/[0.06] px-4 py-3">
                            <span class="text-sm font-bold text-white/60">Итого</span>
                            <span class="text-lg font-extrabold text-amber-400 tabular-nums">{{ number_format($this->cartTotal, 0, '.', ' ') }} сум</span>
                        </div>
                    @endif

                    {{-- Client --}}
                    <div class="mb-4">
                        <label class="mb-1.5 block text-xs font-semibold text-white/50">Клиент (необязательно)</label>
                        <x-search-select
                            :options="$this->filteredClients"
                            searchModel="clientSearch"
                            onSelect="selectClient"
                            labelField="name"
                            subLabelField="phone"
                            placeholder="Поиск клиента..."
                        />
                    </div>

                    {{-- Note --}}
                    <div class="mb-6">
                        <label class="mb-1.5 block text-xs font-semibold text-white/50">Заметка</label>
                        <input type="text" wire:model="note" placeholder="Необязательно..."
                               class="block w-full rounded-xl border border-white/[0.08] bg-white/[0.04] px-4 py-3 text-sm text-white placeholder-white/20 outline-none transition focus:border-amber-500/40 focus:ring-1 focus:ring-amber-500/20">
                    </div>

                    <div class="flex gap-3">
                        <button type="button" wire:click="cancel"
                                class="rounded-xl border border-white/[0.08] px-5 py-2.5 text-sm font-bold text-white/60 transition hover:bg-white/[0.06] hover:text-white">
                            Отмена
                        </button>
                        <button type="button" wire:click="save"
                                class="flex-1 rounded-xl bg-amber-500 px-6 py-2.5 text-sm font-bold text-black transition-all hover:bg-amber-400 active:scale-[0.98]">
                            Провести продажу
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Orders list --}}
    <div class="overflow-hidden rounded-2xl border border-white/[0.06] bg-white/[0.03] shadow-xl backdrop-blur-md">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-white/[0.06] bg-white/[0.03] text-xs font-bold uppercase tracking-wider text-white/30">
                        <th class="px-6 py-4">Время</th>
                        <th class="px-6 py-4">Клиент</th>
                        <th class="px-6 py-4">Состав</th>
                        <th class="px-6 py-4 text-right">Сумма</th>
                        <th class="px-6 py-4 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/[0.04]">
                    @forelse ($this->orders as $order)
                        <tr class="transition-colors hover:bg-white/[0.02]">
                            <td class="whitespace-nowrap px-6 py-4">
                                <div class="font-bold text-white">{{ $order->created_at->format('H:i') }}</div>
                                <div class="text-[10px] text-white/30">{{ $order->created_at->format('d.m') }}</div>
                            </td>
                            <td class="px-6 py-4">
                                @if ($order->client)
                                    <div class="font-medium text-white">{{ $order->client->name }}</div>
                                    <div class="text-xs text-amber-500/60">{{ $order->client->formattedPhone }}</div>
                                @else
                                    <span class="text-white/30">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($order->items as $item)
                                        <span class="inline-flex items-center rounded-full bg-white/[0.06] px-2.5 py-1 text-xs text-white/60">
                                            {{ $item->product?->name ?? '—' }}
                                            <span class="ml-1 font-bold text-white/40">×{{ $item->quantity }}</span>
                                        </span>
                                    @endforeach
                                </div>
                                @if ($order->note)
                                    <div class="mt-1 text-[11px] italic text-white/30">{{ $order->note }}</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-right">
                                <span class="font-extrabold text-emerald-400 tabular-nums">{{ $order->formattedTotal }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end">
                                    <button type="button" wire:click="deleteOrder({{ $order->id }})"
                                            wire:confirm="Отменить продажу? Остатки вернутся на склад."
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-white/[0.06] text-rose-500/50 transition hover:border-rose-500/20 hover:text-rose-500">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-white/20">За этот день продаж нет</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
