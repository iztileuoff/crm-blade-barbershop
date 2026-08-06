<?php

namespace App\Models\Concerns;

use App\Enums\PaymentType;
use App\Models\DebtPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Кассовые суммы транзакции: сколько реально получено и как распределено
 * между наличными и картой. Долг в фактическую кассу не входит.
 *
 * Использующая модель должна реализовать {@see kassaTotalAmount()} и иметь
 * поля payment_type (cast в PaymentType), cash_amount, card_amount, debt_amount.
 */
trait HasCashRegisterAmounts
{
    /**
     * Удаление операции уносит и её погашения: иначе осиротевшие платежи
     * продолжали бы считаться приходом в кассе.
     */
    protected static function bootHasCashRegisterAmounts(): void
    {
        static::deleting(function (self $model): void {
            $model->debtPayments()->delete();
        });
    }

    /**
     * Полная стоимость транзакции до вычета долга.
     */
    abstract public function kassaTotalAmount(): int;

    /**
     * Фактически полученная сумма (в кассе) — стоимость за вычетом долга.
     *
     * Единственный источник истины для кассы: {@see cashReceived} и
     * {@see cardReceived} только разносят эту сумму по способам оплаты и
     * всегда дают в сумме ровно её.
     */
    public int $receivedAmount {
        get => max(0, $this->kassaTotalAmount() - (int) ($this->debt_amount ?? 0));
    }

    /**
     * Часть полученной суммы наличными.
     *
     * Для смешанной оплаты берём введённые наличные, но не больше полученной
     * суммы: битая разбивка не должна раздувать кассу. Если разбивка пустая
     * вовсе — всё уходит в наличные, как это чинит и миграция-починка; иначе
     * один и тот же день до и после неё показывал бы разный расклад по ящику.
     */
    public int $cashReceived {
        get {
            if ($this->payment_type !== PaymentType::Both) {
                return $this->payment_type === PaymentType::Card ? 0 : $this->receivedAmount;
            }

            $cash = max(0, (int) ($this->cash_amount ?? 0));
            $card = max(0, (int) ($this->card_amount ?? 0));

            if ($cash === 0 && $card === 0) {
                return $this->receivedAmount;
            }

            return min($cash, $this->receivedAmount);
        }
    }

    /**
     * Часть полученной суммы картой — остаток после наличных, чтобы
     * «наличные + карта» всегда сходились с {@see receivedAmount}.
     */
    public int $cardReceived {
        get => match ($this->payment_type) {
            PaymentType::Both => $this->receivedAmount - $this->cashReceived,
            PaymentType::Card => $this->receivedAmount,
            default => 0,
        };
    }

    /**
     * Погашения долга по этой операции.
     */
    public function debtPayments(): MorphMany
    {
        return $this->morphMany(DebtPayment::class, 'payable');
    }

    /**
     * Только операции с непогашенным остатком.
     *
     * Отбор идёт в SQL, а не в PHP: `debt_amount` больше не обнуляется при
     * погашении, поэтому «долг был когда-либо» — это вся история, и её нельзя
     * тянуть в память целиком.
     */
    public function scopeWithOutstandingDebt(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query
            ->where('debt_amount', '>', 0)
            ->whereRaw(
                "coalesce((select sum(amount) from debt_payments
                    where debt_payments.payable_type = ?
                      and debt_payments.payable_id = {$table}.id), 0) < {$table}.debt_amount",
                [$this->getMorphClass()],
            );
    }

    /**
     * Сумма непогашенных остатков по отфильтрованному набору — одним запросом.
     *
     * С пагинацией сумму нельзя считать по загруженной коллекции: шапка
     * показывала бы долг одной страницы вместо всех.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSumOutstandingDebt(Builder $query): int
    {
        $table = $this->getTable();

        return (int) $query
            ->toBase()
            ->selectRaw(
                "coalesce(sum({$table}.debt_amount - coalesce((select sum(amount) from debt_payments
                    where debt_payments.payable_type = ?
                      and debt_payments.payable_id = {$table}.id), 0)), 0) as aggregate",
                [$this->getMorphClass()],
            )
            ->value('aggregate');
    }

    /**
     * Сколько из выданного долга уже погашено.
     *
     * Использует withSum('debtPayments as debt_paid_total', 'amount'), если он
     * был подгружен запросом, — иначе считает по связи.
     *
     * Проверяем именно НАЛИЧИЕ ключа: withAggregate строит подзапрос без
     * COALESCE, поэтому у операции без погашений значение равно NULL, и `??`
     * уводил бы в запрос по связи на каждое чтение — то есть ровно в тот N+1,
     * ради которого withSum и добавлялся.
     */
    public int $debtPaid {
        get => (int) (array_key_exists('debt_paid_total', $this->attributes)
            ? $this->attributes['debt_paid_total']
            : $this->debtPayments()->sum('amount'));
    }

    /**
     * Непогашенный остаток долга: выдано минус погашено.
     */
    public int $outstandingDebt {
        get => max(0, (int) ($this->debt_amount ?? 0) - $this->debtPaid);
    }

    /**
     * Разбивка нал/карта не сходится с полученной суммой — операцию нужно
     * поправить вручную. Используется дашбордом для предупреждения кассиру.
     */
    public bool $hasBrokenPaymentSplit {
        get {
            if ($this->payment_type !== PaymentType::Both) {
                return (int) ($this->debt_amount ?? 0) > $this->kassaTotalAmount();
            }

            $entered = (int) ($this->cash_amount ?? 0) + (int) ($this->card_amount ?? 0);

            return $entered !== $this->receivedAmount
                || (int) ($this->debt_amount ?? 0) > $this->kassaTotalAmount();
        }
    }
}
