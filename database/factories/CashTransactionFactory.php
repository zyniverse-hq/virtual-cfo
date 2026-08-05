<?php

namespace Database\Factories;

use App\Models\AccountHead;
use App\Models\CashTransaction;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashTransaction>
 */
class CashTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'date' => fake()->date(),
            'description' => fake()->sentence(),
            'amount' => fake()->randomFloat(2, 10, 1000),
            'account_head_id' => AccountHead::factory(),
        ];
    }
}
