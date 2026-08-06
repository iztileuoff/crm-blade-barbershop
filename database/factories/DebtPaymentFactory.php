<?php

namespace Database\Factories;

use App\Models\DebtPayment;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebtPayment>
 */
class DebtPaymentFactory extends Factory
{
    protected $model = DebtPayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payable_type' => (new Order)->getMorphClass(),
            'payable_id' => fn () => Order::create([
                'total_price' => 100000,
                'payment_type' => 'cash',
                'debt_amount' => 100000,
            ])->id,
            'amount' => fake()->numberBetween(10000, 100000),
            'payment_type' => 'cash',
            'paid_at' => now(),
            'user_id' => null,
        ];
    }
}
