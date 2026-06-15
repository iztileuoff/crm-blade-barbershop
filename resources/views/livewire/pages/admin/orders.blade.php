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

    public ?int $debt_amount = null;

    public bool $debtEnabled = false;

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

    #[Computed]
    public function todayDebt(): int
    {
        return (int) $this->orders->sum('debt_amount');
    }

    public function selectClient(int $id, string $label): void
    {
        $this->client_id = $id;
        $this->clientSearch = $label;
    }

    public function updatedDebtEnabled($value): void
    {
        if (! $value) {
            $this->debt_amount = null;
            $this->resetErrorBag('debt_amount');
        }
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

        $debtAmount = ($this->debt_amount ?? 0) > 0 ? $this->debt_amount : null;

        if ($debtAmount !== null && ! $this->client_id) {
            $this->addError('client_id', 'Клиент обязателен при долге.');

            return;
        }

        $total = $this->cartTotal;

        $order = Order::create([
            'client_id' => $this->client_id,
            'total_price' => $total,
            'note' => $this->note ?: null,
            'debt_amount' => $debtAmount,
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
        $this->reset(['client_id', 'clientSearch', 'note', 'cartItems', 'debt_amount', 'debtEnabled']);
        $this->resetErrorBag();
    }
}; ?>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">Продажи товаров</h1>
            <p class="mt-1 text-sm text-content/40">Касса — розничные продажи</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <input
                type="date"
                wire:model.live="date"
                class="rounded-xl border border-content/10 bg-content/5 px-4 py-2 text-sm text-content shadow-sm transition-colors focus:border-brass focus:outline-none focus:ring-1 focus:ring-brass dark:[color-scheme:dark]"
            >
            <button type="button" wire:click="openCreate"
                    class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-brass to-brass px-5 py-2.5 text-sm font-bold text-black shadow-lg shadow-brass/20 transition-all hover:scale-[1.02] hover:shadow-brass/30 active:scale-[0.98]">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Новая продажа
            </button>
        </div>
    </div>

    {{-- Day total --}}
    <div @class(['mb-8 grid gap-4', 'sm:grid-cols-2' => $this->todayDebt > 0])>
        <div class="overflow-hidden rounded-2xl border border-success/20 bg-gradient-to-br from-success/10 to-success/[0.02] p-6 shadow-xl backdrop-blur-md">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold uppercase tracking-widest text-success/70">Выручка за день</span>
                    <div class="mt-2 font-display text-4xl font-bold tabular-nums text-content">
                        {{ number_format($this->todayTotal, 0, '.', ' ') }} сум
                    </div>
                    <div class="mt-1 text-xs text-success/60">{{ $this->orders->count() }} продаж{{ match(true) { $this->orders->count() % 10 === 1 && $this->orders->count() % 100 !== 11 => 'а', in_array($this->orders->count() % 10, [2,3,4]) && !in_array($this->orders->count() % 100, [12,13,14]) => 'и', default => '' } }}</div>
                </div>
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-success/15 text-success">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z" /></svg>
                </div>
            </div>
        </div>
        @if ($this->todayDebt > 0)
            <div class="overflow-hidden rounded-2xl border border-danger/20 bg-gradient-to-br from-danger/10 to-danger/[0.02] p-6 shadow-xl backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-widest text-danger/70">Долги за день</span>
                        <div class="mt-2 font-display text-4xl font-bold tabular-nums text-content">
                            {{ number_format($this->todayDebt, 0, '.', ' ') }} сум
                        </div>
                        <a href="{{ route('admin.debts') }}" class="mt-1 inline-flex items-center gap-1 text-xs text-danger/60 hover:text-danger">
                            Все долги →
                        </a>
                    </div>
                    <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-danger/15 text-danger">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- New order form --}}
    @if ($showForm)
        <div class="mb-8 overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
            <div class="border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
                <h3 class="text-sm font-bold text-content">Новая продажа</h3>
            </div>
            <div class="grid gap-0 lg:grid-cols-2">
                {{-- Left: product selector --}}
                <div class="border-b border-content/[0.06] p-6 lg:border-b-0 lg:border-r">
                    <p class="mb-4 text-xs font-semibold uppercase tracking-wider text-content/40">Выберите товары</p>
                    @if ($this->availableProducts->isEmpty())
                        <p class="text-sm text-content/30">Нет товаров в наличии.</p>
                    @else
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($this->availableProducts as $product)
                                <button type="button" wire:click="addToCart({{ $product->id }})"
                                        class="group flex items-center justify-between rounded-xl border border-content/[0.06] bg-content/[0.02] px-4 py-3 text-left transition hover:border-brass/30 hover:bg-brass/[0.05]">
                                    <div>
                                        <div class="text-sm font-bold text-content">{{ $product->name }}</div>
                                        <div class="text-xs text-brass-ink/70">{{ $product->formattedPrice }}</div>
                                    </div>
                                    <div class="ml-3 shrink-0 text-right">
                                        <span @class([
                                            'text-xs font-bold tabular-nums',
                                            'text-success' => $product->stock > 5,
                                            'text-brass-ink' => $product->stock <= 5,
                                        ])>{{ $product->stock }} шт</span>
                                        <div class="mt-0.5 flex h-6 w-6 items-center justify-center rounded-lg bg-brass/10 text-brass-ink opacity-0 transition group-hover:opacity-100">
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
                    <p class="mb-4 text-xs font-semibold uppercase tracking-wider text-content/40">Корзина</p>

                    @error('cart')
                        <p class="mb-4 rounded-xl bg-danger/10 px-4 py-3 text-xs font-bold text-danger">{{ $message }}</p>
                    @enderror

                    @if (empty($cartItems))
                        <p class="mb-6 text-sm text-content/20">Товары не добавлены</p>
                    @else
                        <div class="mb-6 space-y-2">
                            @foreach ($cartItems as $index => $item)
                                <div class="flex items-center gap-3 rounded-xl border border-content/[0.06] bg-content/[0.02] px-4 py-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-bold text-content">{{ $item['name'] }}</div>
                                        <div class="text-xs text-content/40">{{ number_format($item['price'], 0, '.', ' ') }} сум × {{ $item['quantity'] }} = <span class="font-bold text-brass-ink">{{ number_format($item['price'] * $item['quantity'], 0, '.', ' ') }} сум</span></div>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1.5">
                                        <button type="button" wire:click="updateQuantity({{ $index }}, {{ $item['quantity'] - 1 }})"
                                                class="flex h-7 w-7 items-center justify-center rounded-lg border border-content/[0.06] text-content/40 transition hover:text-danger">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" /></svg>
                                        </button>
                                        <span class="w-6 text-center text-sm font-bold text-content tabular-nums">{{ $item['quantity'] }}</span>
                                        <button type="button" wire:click="updateQuantity({{ $index }}, {{ $item['quantity'] + 1 }})"
                                                class="flex h-7 w-7 items-center justify-center rounded-lg border border-content/[0.06] text-content/40 transition hover:text-success">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        </button>
                                    </div>
                                    <button type="button" wire:click="removeFromCart({{ $index }})" class="text-content/20 transition hover:text-danger">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        <div class="mb-6 flex items-center justify-between rounded-xl border border-brass/20 bg-brass/[0.06] px-4 py-3">
                            <span class="text-sm font-bold text-content/60">Итого</span>
                            <span class="text-lg font-extrabold text-brass-ink tabular-nums">{{ number_format($this->cartTotal, 0, '.', ' ') }} сум</span>
                        </div>
                    @endif

                    {{-- Client --}}
                    <div class="mb-4">
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">
                            Клиент
                            @if ($debtEnabled)
                                <span class="ml-1 text-danger">*</span>
                            @else
                                <span class="ml-1 text-content/25">(необязательно)</span>
                            @endif
                        </label>
                        <x-search-select
                            :options="$this->filteredClients"
                            searchModel="clientSearch"
                            onSelect="selectClient"
                            labelField="name"
                            subLabelField="phone"
                            placeholder="Поиск клиента..."
                        />
                        @error('client_id') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>

                    {{-- Debt --}}
                    <div class="mb-4">
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
                                    ])>В долг</label>
                                    <p class="mt-0.5 text-[10px] text-content/30">Если клиент не платит сейчас</p>
                                </div>
                                <button type="button" wire:click="$toggle('debtEnabled')"
                                        role="switch" aria-checked="{{ $debtEnabled ? 'true' : 'false' }}"
                                        @class([
                                            'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors outline-none',
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
                                    <input type="number" wire:model.live="debt_amount"
                                           placeholder="0" min="0"
                                           class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] py-3 pl-4 pr-12 text-sm text-content outline-none transition focus:border-danger/40 focus:ring-1 focus:ring-danger/20">
                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[10px] font-medium text-content/25">сум</span>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Note --}}
                    <div class="mb-6">
                        <label class="mb-1.5 block text-xs font-semibold text-content/50">Заметка</label>
                        <input type="text" wire:model="note" placeholder="Необязательно..."
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                    </div>

                    <div class="flex gap-3">
                        <button type="button" wire:click="cancel"
                                class="rounded-xl border border-content/[0.08] px-5 py-2.5 text-sm font-bold text-content/60 transition hover:bg-content/[0.06] hover:text-content">
                            Отмена
                        </button>
                        <button type="button" wire:click="save"
                                class="flex-1 rounded-xl bg-brass px-6 py-2.5 text-sm font-bold text-black transition-all hover:bg-brass-bright active:scale-[0.98]">
                            Провести продажу
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Orders list --}}
    <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content/30">
                        <th class="px-6 py-4">Время</th>
                        <th class="px-6 py-4">Клиент</th>
                        <th class="px-6 py-4">Состав</th>
                        <th class="px-6 py-4 text-right">Сумма</th>
                        <th class="px-6 py-4 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-content/[0.04]">
                    @forelse ($this->orders as $order)
                        <tr class="transition-colors hover:bg-content/[0.02]">
                            <td class="whitespace-nowrap px-6 py-4">
                                <div class="font-bold text-content">{{ $order->created_at->format('H:i') }}</div>
                                <div class="text-[10px] text-content/30">{{ $order->created_at->format('d.m') }}</div>
                            </td>
                            <td class="px-6 py-4">
                                @if ($order->client)
                                    <div class="font-medium text-content">{{ $order->client->name }}</div>
                                    <div class="text-xs text-brass-ink/60">{{ $order->client->formattedPhone }}</div>
                                @else
                                    <span class="text-content/30">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($order->items as $item)
                                        <span class="inline-flex items-center rounded-full bg-content/[0.06] px-2.5 py-1 text-xs text-content/60">
                                            {{ $item->product?->name ?? '—' }}
                                            <span class="ml-1 font-bold text-content/40">×{{ $item->quantity }}</span>
                                        </span>
                                    @endforeach
                                </div>
                                @if ($order->note)
                                    <div class="mt-1 text-[11px] italic text-content/30">{{ $order->note }}</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-right">
                                <span class="font-extrabold text-success tabular-nums">{{ $order->formattedTotal }}</span>
                                @if ($order->hasDebt)
                                    <div class="mt-1 flex items-center justify-end gap-1 text-[10px] font-bold text-danger">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                        Долг: {{ $order->formattedDebt }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end">
                                    <button type="button" wire:click="deleteOrder({{ $order->id }})"
                                            wire:confirm="Отменить продажу? Остатки вернутся на склад."
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-content/[0.06] text-danger/50 transition hover:border-danger/20 hover:text-danger">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-content/20">За этот день продаж нет</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
