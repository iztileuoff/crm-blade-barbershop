<?php

namespace App\Support;

/**
 * Перекрёстные проверки денежных полей операции (записи или продажи).
 *
 * Раньше их не было ни на одном экране ввода, из-за чего в базе появились
 * операции, где «наличные + карта» не сходятся с ценой, а долг больше самой
 * цены — касса на таких строках считает мусор.
 */
class PaymentSplit
{
    /**
     * Ошибки денежных полей: поле => сообщение. Пустой массив — всё сходится.
     *
     * @return array<string, string>
     */
    public static function errors(
        string $paymentType,
        int $total,
        ?int $cash,
        ?int $card,
        ?int $debt,
        int $alreadyPaid = 0,
    ): array {
        $debt = (int) ($debt ?? 0);

        if ($debt > $total) {
            return ['debt_amount' => __('payments.err_debt_over_price')];
        }

        // Долг нельзя опустить ниже уже принятой по нему суммы: те деньги лежат
        // в кассе дня платежа, и уменьшение долга засчитало бы их второй раз —
        // в кассе дня самой операции.
        if ($alreadyPaid > 0 && $debt < $alreadyPaid) {
            return ['debt_amount' => __('payments.err_debt_below_paid', [
                'paid' => number_format($alreadyPaid, 0, '.', ' '),
            ])];
        }

        if ($paymentType !== 'both') {
            return [];
        }

        $cash = (int) ($cash ?? 0);
        $card = (int) ($card ?? 0);
        $expected = $total - $debt;

        // Операция целиком ушла в долг — разносить по способам оплаты нечего.
        // Но и остатки в полях оставлять нельзя: дашборд тут же пометит такую
        // строку как битую, а у продаж нет экрана правки, чтобы её починить.
        if ($expected === 0) {
            return $cash === 0 && $card === 0
                ? []
                : ['cash_amount' => __('payments.err_split_not_needed')];
        }

        if ($cash <= 0 && $card <= 0) {
            return ['cash_amount' => __('payments.err_split_required')];
        }

        if ($cash + $card !== $expected) {
            return ['cash_amount' => __('payments.err_split_mismatch', [
                'expected' => number_format($expected, 0, '.', ' '),
            ])];
        }

        return [];
    }
}
