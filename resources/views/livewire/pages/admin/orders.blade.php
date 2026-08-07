<?php

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\PaymentSplit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component
{
    public string $date = '';

    public bool $showForm = false;

    public ?int $client_id = null;

    public string $clientSearch = '';

    public string $productSearch = '';

    #[Validate('nullable|string|max:500')]
    public string $note = '';

    #[Validate('nullable|integer|min:0')]
    public ?int $debt_amount = null;

    public bool $debtEnabled = false;

    #[Validate('required|in:cash,card,both')]
    public string $payment_type = 'cash';

    #[Validate('nullable|integer|min:0')]
    public ?int $cash_amount = null;

    #[Validate('nullable|integer|min:0')]
    public ?int $card_amount = null;

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
            ->withSum('debtPayments as debt_paid_total', 'amount')
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
        return Product::active()
            ->where('stock', '>', 0)
            ->when(trim($this->productSearch) !== '', fn ($q) => $q->where('name', 'like', '%'.trim($this->productSearch).'%'))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function cartTotal(): int
    {
        return array_sum(array_map(fn ($item) => $item['quantity'] * $item['price'], $this->cartItems));
    }

    /**
     * Реально полученные деньги за день — то, что сверяет кассир. Долг из
     * этой суммы исключён: он ещё не в кассе (см. HasCashRegisterAmounts).
     */
    #[Computed]
    public function todayTotal(): int
    {
        return (int) $this->orders->sum(fn (Order $o) => $o->receivedAmount);
    }

    /**
     * Валовый оборот дня — стоимость всех продаж, включая ещё не полученный
     * долг. Показывается рядом с кассой отдельной строкой, чтобы не путать
     * с деньгами, которые реально лежат в ящике.
     */
    #[Computed]
    public function todayTurnover(): int
    {
        return (int) $this->orders->sum('total_price');
    }

    /**
     * Непогашенный остаток, а не выданный долг — иначе шапка показывает долг,
     * которого ни в одной строке уже нет.
     */
    #[Computed]
    public function todayDebt(): int
    {
        return (int) $this->orders->sum(fn (Order $o) => $o->outstandingDebt);
    }

    /**
     * Подтверждённый выбор клиента — тот, что реально уедет в продажу.
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
     */
    public function selectClient(int $id): void
    {
        $client = Client::find($id);

        if ($client === null) {
            return;
        }

        $this->client_id = $client->id;
        $this->clientSearch = $client->phone ? "{$client->name} ({$client->phone})" : $client->name;
    }

    /**
     * Текст поиска правят — значит, выбранный id больше не подтверждён.
     * Иначе долг уедет на клиента, которого в поле уже не видно.
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

    /**
     * Клиент обязателен, только если продажа уходит в долг. Объявлено методом,
     * чтобы validate() без аргументов не терял правила #[Validate].
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'client_id' => ($this->debt_amount ?? 0) > 0
                ? 'required|exists:clients,id'
                : 'nullable|exists:clients,id',
        ];
    }

    public function save(): void
    {
        $this->validate();

        if (empty($this->cartItems)) {
            $this->addError('cart', __('orders.err_empty_cart'));

            return;
        }

        $debtAmount = ($this->debt_amount ?? 0) > 0 ? $this->debt_amount : null;

        if ($debtAmount !== null && ! $this->client_id) {
            $this->addError('client_id', __('orders.err_debt_client'));

            return;
        }

        $total = $this->cartTotal;

        $moneyErrors = PaymentSplit::errors(
            $this->payment_type,
            $total,
            $this->cash_amount,
            $this->card_amount,
            $debtAmount,
        );

        if ($moneyErrors !== []) {
            foreach ($moneyErrors as $field => $message) {
                $this->addError($field, $message);
            }

            return;
        }

        // Заказ, позиции и списание остатка — одна операция: сбой посередине
        // иначе оставляет продажу с частью позиций и частью списанного товара.
        DB::transaction(function () use ($total, $debtAmount): void {
            $order = Order::create([
                'client_id' => $this->client_id,
                'total_price' => $total,
                'note' => $this->note ?: null,
                'debt_amount' => $debtAmount,
                'payment_type' => $this->payment_type,
                'cash_amount' => $this->payment_type === 'both' ? $this->cash_amount : null,
                'card_amount' => $this->payment_type === 'both' ? $this->card_amount : null,
            ]);

            foreach ($this->cartItems as $item) {
                $product = Product::query()->lockForUpdate()->find($item['product_id']);

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
        });

        unset($this->orders, $this->availableProducts);
        $this->showForm = false;
        $this->resetForm();

        $this->dispatch('saved');
    }

    public function deleteOrder(int $id): void
    {
        $this->resetErrorBag('delete');

        $order = Order::with('items')->findOrFail($id);

        // Удаление унесёт и погашения — а они лежат в кассе других дней.
        // Молча двигать закрытый день нельзя. Ошибка идёт в ключ `delete`:
        // его баннер живёт над таблицей, а не внутри закрытой формы.
        if ($order->debtPayments()->exists()) {
            $this->addError('delete', __('orders.err_delete_has_payments'));

            return;
        }

        // Сначала удаляем (вместе с погашениями через хук модели), потом
        // возвращаем остаток — и всё внутри одной транзакции, иначе сбой на
        // удалении оставляет товар возвращённым дважды.
        DB::transaction(function () use ($order): void {
            $items = $order->items->map(fn (OrderItem $item) => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
            ])->all();

            $order->delete();

            foreach ($items as $item) {
                Product::query()->whereKey($item['product_id'])->increment('stock', $item['quantity']);
            }
        });

        unset($this->orders, $this->availableProducts);
    }

    public function dismissDeleteError(): void
    {
        $this->resetErrorBag('delete');
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset(['client_id', 'clientSearch', 'productSearch', 'note', 'cartItems', 'debt_amount', 'debtEnabled', 'cash_amount', 'card_amount']);
        $this->payment_type = 'cash';
        $this->resetErrorBag();
    }
}; ?>

<x-slot:title>{{ __('orders.page_title') }}</x-slot:title>

<div class="animate-fade-in-up">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl font-semibold uppercase tracking-tight text-content">{{ __('orders.title') }}</h1>
            <p class="mt-1 text-sm text-content/40">{{ __('orders.subtitle') }}</p>
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
                {{ __('orders.new') }}
            </button>
        </div>
    </div>

    {{-- Day total --}}
    <div @class(['mb-8 grid gap-4', 'sm:grid-cols-2' => $this->todayDebt > 0])>
        <div class="overflow-hidden rounded-2xl border border-success/20 bg-gradient-to-br from-success/10 to-success/[0.02] p-6 shadow-xl backdrop-blur-md">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold uppercase tracking-widest text-success/70">{{ __('orders.revenue_day') }}</span>
                    <div class="mt-2 font-display text-4xl font-bold tabular-nums text-content">
                        {{ number_format($this->todayTotal, 0, '.', ' ') }} {{ __('common.currency') }}
                    </div>
                    <div class="mt-1 text-xs text-success/60">{{ __('orders.sales_count', ['count' => $this->orders->count()]) }}</div>
                    {{-- Оборот показываем, только когда он расходится с кассой: одинаковые числа рядом лишь путают. --}}
                    @if ($this->todayTurnover !== $this->todayTotal)
                        <div class="mt-0.5 text-[10px] text-content-muted">{{ __('orders.turnover_day') }}: {{ number_format($this->todayTurnover, 0, '.', ' ') }} {{ __('common.currency') }}</div>
                    @endif
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
                        <span class="text-xs font-bold uppercase tracking-widest text-danger/70">{{ __('orders.debt_day') }}</span>
                        <div class="mt-2 font-display text-4xl font-bold tabular-nums text-content">
                            {{ number_format($this->todayDebt, 0, '.', ' ') }} {{ __('common.currency') }}
                        </div>
                        <a href="{{ route('admin.debts') }}" class="mt-1 inline-flex items-center gap-1 text-xs text-danger/60 hover:text-danger">
                            {{ __('orders.all_debts') }}
                        </a>
                    </div>
                    <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-danger/15 text-danger">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Заблокированное удаление: баннер живёт над таблицей, вне формы --}}
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

    {{-- New order form --}}
    @if ($showForm)
        {{-- Своя область для липкой панели: без неё containing block тянется до
             конца страницы, и панель продолжала бы висеть поверх списка продаж. --}}
        <div class="relative">
        <div class="mb-8 overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
            <div class="border-b border-content/[0.06] bg-content/[0.03] px-6 py-4">
                <h3 class="text-sm font-bold text-content">{{ __('orders.new') }}</h3>
            </div>
            <form id="orderForm" wire:submit="save" class="grid gap-0 lg:grid-cols-2">
                {{-- Left: product selector --}}
                <div class="border-b border-content/[0.06] p-6 lg:border-b-0 lg:border-r">
                    <p class="mb-4 text-xs font-semibold uppercase tracking-wider text-content/40">{{ __('orders.select_products') }}</p>
                    <div class="relative mb-4">
                        <svg class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-content-subtle" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                        <input type="text" wire:model.live.debounce.300ms="productSearch"
                               placeholder="{{ __('orders.product_search_placeholder') }}"
                               aria-label="{{ __('orders.product_search_placeholder') }}"
                               x-data x-init="$nextTick(() => $el.focus())"
                               class="w-full rounded-xl border border-content/[0.08] bg-content/[0.04] py-2.5 pl-10 pr-4 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                    </div>
                    @if ($this->availableProducts->isEmpty())
                        <p class="text-sm text-content-muted">{{ trim($productSearch) !== '' ? __('common.nothing_found') : __('orders.no_products') }}</p>
                    @else
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($this->availableProducts as $product)
                                <button type="button" wire:click="addToCart({{ $product->id }})" wire:key="tile-{{ $product->id }}"
                                        class="group flex items-center justify-between rounded-xl border border-content/[0.06] bg-content/[0.02] px-4 py-3 text-left transition hover:border-brass/30 hover:bg-brass/[0.05] focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-brass/40">
                                    <div>
                                        <div class="text-sm font-bold text-content">{{ $product->name }}</div>
                                        <div class="text-xs text-brass-ink/70">{{ $product->formattedPrice }}</div>
                                    </div>
                                    <div class="ml-3 shrink-0 text-right">
                                        <span @class([
                                            'text-xs font-bold tabular-nums',
                                            'text-success' => $product->stock > 5,
                                            'text-brass-ink' => $product->stock <= 5,
                                        ])>{{ $product->stock }} {{ __('common.pieces') }}</span>
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
                    <p class="mb-4 text-xs font-semibold uppercase tracking-wider text-content/40">{{ __('orders.cart') }}</p>

                    @error('cart')
                        <p class="mb-4 rounded-xl bg-danger/10 px-4 py-3 text-xs font-bold text-danger">{{ $message }}</p>
                    @enderror

                    @if (empty($cartItems))
                        <p class="mb-6 text-sm text-content-muted">{{ __('orders.cart_empty') }}</p>
                    @else
                        <div class="mb-6 space-y-2">
                            @foreach ($cartItems as $index => $item)
                                <div wire:key="cart-{{ $item['product_id'] }}" class="flex items-center gap-3 rounded-xl border border-content/[0.06] bg-content/[0.02] px-4 py-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-bold text-content">{{ $item['name'] }}</div>
                                        <div class="text-xs text-content/40">{{ number_format($item['price'], 0, '.', ' ') }} {{ __('common.currency') }} × {{ $item['quantity'] }} = <span class="font-bold text-brass-ink">{{ number_format($item['price'] * $item['quantity'], 0, '.', ' ') }} {{ __('common.currency') }}</span></div>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1.5">
                                        <button type="button" wire:click="updateQuantity({{ $index }}, {{ $item['quantity'] - 1 }})"
                                                title="{{ __('orders.decrease_qty') }}" aria-label="{{ __('orders.decrease_qty') }}"
                                                class="flex h-7 w-7 items-center justify-center rounded-lg border border-content/[0.06] text-content/40 transition hover:text-danger focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-danger/40">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" /></svg>
                                        </button>
                                        <span class="w-6 text-center text-sm font-bold text-content tabular-nums">{{ $item['quantity'] }}</span>
                                        <button type="button" wire:click="updateQuantity({{ $index }}, {{ $item['quantity'] + 1 }})"
                                                title="{{ __('orders.increase_qty') }}" aria-label="{{ __('orders.increase_qty') }}"
                                                class="flex h-7 w-7 items-center justify-center rounded-lg border border-content/[0.06] text-content/40 transition hover:text-success focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-success/40">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        </button>
                                    </div>
                                    <button type="button" wire:click="removeFromCart({{ $index }})"
                                            title="{{ __('orders.remove_item') }}" aria-label="{{ __('orders.remove_item') }}"
                                            class="text-content-subtle transition hover:text-danger focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-danger/40 rounded">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Payment method --}}
                    <div class="mb-4">
                        <label for="order-payment-type" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('orders.payment_method') }}</label>
                        <div class="relative">
                            <select id="order-payment-type" wire:model.live="payment_type"
                                    class="block w-full appearance-none rounded-xl border border-content/[0.08] bg-surface-sunken py-3 pl-4 pr-10 text-sm text-content outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20 [&>option]:bg-surface-raised">
                                <option value="cash">{{ __('enums.payment_type.cash') }}</option>
                                <option value="card">{{ __('enums.payment_type.card') }}</option>
                                <option value="both">{{ __('enums.payment_type.both') }}</option>
                            </select>
                            <div class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-content-subtle">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                            </div>
                        </div>

                        @if ($payment_type === 'both')
                            <div class="mt-3 grid grid-cols-2 gap-4 rounded-xl border border-royal/20 bg-royal/5 p-4">
                                <div>
                                    <label for="order-cash-amount" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('enums.payment_type.cash') }} ({{ __('common.currency') }})</label>
                                    <div class="relative">
                                        {{-- .debounce: без него сумму «120000» кассир отправляет шестью
                                             запросами, каждый из которых перевалидирует сплит и на
                                             планшетном интернете успевает мигнуть ошибкой.
                                             .number: свойство типизировано как ?int, и без него ответ
                                             приходит числом на отправленную строку. Livewire считает это
                                             правкой значения на сервере и перетирает цифры, набранные за
                                             время запроса, — «120» откатывалось в «12». --}}
                                        <input type="number" id="order-cash-amount" wire:model.number.live.debounce.500ms="cash_amount"
                                               placeholder="0" min="0"
                                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] py-3 pl-4 pr-12 text-sm text-content outline-none transition focus:border-success/40 focus:ring-1 focus:ring-success/20">
                                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[10px] font-medium text-content/25">{{ __('common.currency') }}</span>
                                    </div>
                                </div>
                                <div>
                                    <label for="order-card-amount" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('enums.payment_type.card') }} ({{ __('common.currency') }})</label>
                                    <div class="relative">
                                        <input type="number" id="order-card-amount" wire:model.number.live.debounce.500ms="card_amount"
                                               placeholder="0" min="0"
                                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] py-3 pl-4 pr-12 text-sm text-content outline-none transition focus:border-info/40 focus:ring-1 focus:ring-info/20">
                                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[10px] font-medium text-content/25">{{ __('common.currency') }}</span>
                                    </div>
                                </div>
                                <p class="col-span-2 text-[10px] text-content-muted">{{ __('orders.both_hint') }}</p>
                            </div>
                        @endif
                        @error('payment_type') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                        @error('cash_amount') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                        @error('card_amount') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>

                    {{-- Client --}}
                    <div class="mb-4">
                        <x-search-select
                            :options="$this->filteredClients"
                            searchModel="clientSearch"
                            inputId="order-client"
                            onSelect="selectClient"
                            labelField="name"
                            subLabelField="phone"
                            placeholder="{{ __('orders.search_client') }}"
                            :selectedLabel="$this->selectedClient?->name"
                            onClear="clearClient"
                        >
                            <x-slot:label>
                                {{ __('common.client') }}
                                @if ($debtEnabled)
                                    <span class="ml-1 text-danger">*</span>
                                @else
                                    <span class="ml-1 text-content/25">{{ __('common.optional') }}</span>
                                @endif
                            </x-slot:label>
                        </x-search-select>
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
                                    <label id="order-debt-toggle-label" @class([
                                        'text-xs font-semibold transition-colors',
                                        'text-danger/80' => $debtEnabled,
                                        'text-content/50' => ! $debtEnabled,
                                    ])>{{ __('orders.on_debt') }}</label>
                                    <p class="mt-0.5 text-[10px] text-content-muted">{{ __('orders.debt_hint') }}</p>
                                </div>
                                <button type="button" wire:click="$toggle('debtEnabled')"
                                        role="switch" aria-checked="{{ $debtEnabled ? 'true' : 'false' }}"
                                        aria-labelledby="order-debt-toggle-label"
                                        @class([
                                            'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors outline-none focus-visible:ring-2 focus-visible:ring-danger/40 focus-visible:ring-offset-2 focus-visible:ring-offset-surface',
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
                                    <input type="number" id="order-debt-amount" wire:model.number.live="debt_amount"
                                           aria-label="{{ __('orders.on_debt') }}"
                                           placeholder="0" min="0"
                                           class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] py-3 pl-4 pr-12 text-sm text-content outline-none transition focus:border-danger/40 focus:ring-1 focus:ring-danger/20">
                                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[10px] font-medium text-content/25">{{ __('common.currency') }}</span>
                                </div>
                            @endif
                            {{-- Вне @if: ошибка «долг меньше уже принятого» приходит при выключенном тумблере --}}
                            @error('debt_amount') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    {{-- Note --}}
                    <div class="mb-6">
                        <label for="order-note" class="mb-1.5 block text-xs font-semibold text-content/50">{{ __('common.note') }}</label>
                        <input type="text" id="order-note" wire:model="note" placeholder="{{ __('orders.note_placeholder') }}"
                               class="block w-full rounded-xl border border-content/[0.08] bg-content/[0.04] px-4 py-3 text-sm text-content placeholder-content/20 outline-none transition focus:border-brass/40 focus:ring-1 focus:ring-brass/20">
                        @error('note') <p class="mt-1.5 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>

                </div>
            </form>
        </div>

        {{-- Sticky mobile summary bar: item count, total and submit stay visible
             while scrolling the product grid above, so adding a product is felt
             right away — otherwise the cart lives a screen and a half below.
             On large screens the grid and cart already fit without scrolling,
             so the bar just settles into normal flow below the form. --}}
        <div class="sticky bottom-0 z-20 mb-8 flex items-center justify-between gap-3 rounded-2xl border border-content/[0.08] bg-surface/95 px-4 py-3 shadow-[0_-8px_24px_rgba(0,0,0,0.18)] backdrop-blur-md lg:static lg:border-content/[0.06] lg:bg-content/[0.03] lg:px-6 lg:py-4 lg:shadow-none">
            <div>
                <div class="text-[10px] font-bold uppercase tracking-widest text-content/40">
                    {{ __('orders.cart') }} · {{ __('orders.cart_count', ['count' => count($cartItems)]) }}
                </div>
                <div class="text-lg font-extrabold text-brass-ink tabular-nums">
                    {{ number_format($this->cartTotal, 0, '.', ' ') }} {{ __('common.currency') }}
                </div>
            </div>
            <div class="flex shrink-0 gap-2">
                <button type="button" wire:click="cancel"
                        class="rounded-xl border border-content/[0.08] px-4 py-2.5 text-sm font-bold text-content/60 transition hover:bg-content/[0.06] hover:text-content">
                    {{ __('common.cancel') }}
                </button>
                <x-submit-button form="orderForm">{{ __('orders.submit') }}</x-submit-button>
            </div>
        </div>
        </div>
    @endif

    {{-- Orders list --}}
    <div class="overflow-hidden rounded-2xl border border-content/[0.06] bg-content/[0.03] shadow-xl backdrop-blur-md">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-content/[0.06] bg-content/[0.03] text-xs font-bold uppercase tracking-wider text-content-muted">
                        <th class="px-6 py-4">{{ __('common.time') }}</th>
                        <th class="px-6 py-4">{{ __('common.client') }}</th>
                        <th class="px-6 py-4">{{ __('orders.composition') }}</th>
                        <th class="px-6 py-4 text-right">{{ __('common.amount') }}</th>
                        <th class="px-6 py-4 text-right">{{ __('common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-content/[0.04]">
                    @forelse ($this->orders as $order)
                        <tr wire:key="order-{{ $order->id }}" class="transition-colors hover:bg-content/[0.02]">
                            <td class="whitespace-nowrap px-6 py-4">
                                <div class="font-bold text-content">{{ $order->created_at->format('H:i') }}</div>
                                <div class="text-[10px] text-content-muted">{{ $order->created_at->format('d.m') }}</div>
                            </td>
                            <td class="px-6 py-4">
                                @if ($order->client)
                                    <div class="font-medium text-content">{{ $order->client->name }}</div>
                                    <div class="text-xs text-brass-ink/60">{{ $order->client->formattedPhone }}</div>
                                @else
                                    <span class="text-content-muted">—</span>
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
                                    <div class="mt-1 text-[11px] italic text-content-muted">{{ $order->note }}</div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-right">
                                <span class="font-extrabold text-success tabular-nums">{{ $order->formattedTotal }}</span>
                                <div class="mt-1 flex flex-wrap items-center justify-end gap-1.5">
                                    @if ($order->payment_type === \App\Enums\PaymentType::Both)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-[9px] font-bold text-success">{{ __('orders.cash_short') }}: {{ number_format($order->cashReceived, 0, '.', ' ') }}</span>
                                        <span class="inline-flex items-center gap-1 rounded-full bg-info/10 px-2 py-0.5 text-[9px] font-bold text-info">{{ __('orders.card_short') }}: {{ number_format($order->cardReceived, 0, '.', ' ') }}</span>
                                    @else
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[9px] font-bold',
                                            'bg-success/10 text-success' => $order->payment_type === \App\Enums\PaymentType::Cash,
                                            'bg-info/10 text-info' => $order->payment_type === \App\Enums\PaymentType::Card,
                                        ])>{{ ($order->payment_type ?? \App\Enums\PaymentType::Cash)->label() }}</span>
                                    @endif
                                </div>
                                @if ($order->hasDebt)
                                    <div class="mt-1 flex items-center justify-end gap-1 text-[10px] font-bold text-danger">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                        {{ __('common.debt') }}: {{ $order->formattedDebt }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end">
                                    {{-- Удаление продажи возвращает товар на склад и пересчитывает
                                         кассу — на планшете это заметная пауза, и без индикатора
                                         кассир жмёт кнопку второй раз. --}}
                                    <button type="button" wire:click="deleteOrder({{ $order->id }})"
                                            wire:confirm="{{ __('orders.delete_confirm') }}"
                                            wire:loading.attr="disabled" wire:target="deleteOrder({{ $order->id }})"
                                            title="{{ __('common.delete') }}" aria-label="{{ __('common.delete') }}"
                                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-content/[0.06] text-danger/50 transition hover:border-danger/20 hover:text-danger focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-danger/40 disabled:cursor-not-allowed disabled:opacity-40">
                                        <svg wire:loading.remove wire:target="deleteOrder({{ $order->id }})" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                        <svg wire:loading wire:target="deleteOrder({{ $order->id }})" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-content-muted">{{ __('orders.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
