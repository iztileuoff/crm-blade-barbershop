<?php

namespace App\Models\Concerns;

use App\Enums\PaymentType;

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
     * Полная стоимость транзакции до вычета долга.
     */
    abstract public function kassaTotalAmount(): int;

    /**
     * Фактически полученная сумма (в кассе) — стоимость за вычетом долга.
     */
    public int $receivedAmount {
        get => max(0, $this->kassaTotalAmount() - (int) ($this->debt_amount ?? 0));
    }

    /**
     * Часть полученной суммы наличными.
     */
    public int $cashReceived {
        get => match ($this->payment_type) {
            PaymentType::Both => (int) ($this->cash_amount ?? 0),
            PaymentType::Card => 0,
            default => $this->receivedAmount,
        };
    }

    /**
     * Часть полученной суммы картой.
     */
    public int $cardReceived {
        get => match ($this->payment_type) {
            PaymentType::Both => (int) ($this->card_amount ?? 0),
            PaymentType::Card => $this->receivedAmount,
            default => 0,
        };
    }
}
