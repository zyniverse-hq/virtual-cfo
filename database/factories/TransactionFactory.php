<?php

namespace Database\Factories;

use App\Enums\MappingType;
use App\Enums\ReconciliationStatus;
use App\Models\AccountHead;
use App\Models\Company;
use App\Models\ImportedFile;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $isDebit = fake()->boolean();

        return [
            'company_id' => Company::factory(),
            'imported_file_id' => ImportedFile::factory(),
            'currency' => 'INR',
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
            'description' => fake()->randomElement([
                'NEFT-'.fake()->numerify('######').'-'.fake()->company(),
                'UPI/'.fake()->numerify('########').'/'.fake()->name(),
                'ATM WDL-'.fake()->city(),
                'SALARY '.fake()->monthName(),
                'EMI '.fake()->company(),
                'CC PAYMENT',
                'RTGS-'.fake()->company(),
                'POS '.fake()->company().' '.fake()->city(),
            ]),
            'reference_number' => fake()->optional(0.7)->numerify('REF########'),
            'debit' => $isDebit ? fake()->randomFloat(2, 100, 50000) : null,
            'credit' => $isDebit ? null : fake()->randomFloat(2, 100, 100000),
            'balance' => fake()->randomFloat(2, 1000, 500000),
            'account_head_id' => null,
            'mapping_type' => MappingType::Unmapped,
            'ai_confidence' => null,
            'raw_data' => null,
            'bank_format' => null,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ];
    }

    public function unmapped(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_head_id' => null,
            'mapping_type' => MappingType::Unmapped,
            'ai_confidence' => null,
        ]);
    }

    public function mapped(?AccountHead $head = null): static
    {
        return $this->state(fn (array $attributes) => [
            'account_head_id' => $head?->id ?? AccountHead::factory(),
            'mapping_type' => MappingType::Manual,
            'ai_confidence' => null,
        ]);
    }

    public function autoMapped(?AccountHead $head = null): static
    {
        return $this->state(fn (array $attributes) => [
            'account_head_id' => $head?->id ?? AccountHead::factory(),
            'mapping_type' => MappingType::Auto,
            'ai_confidence' => null,
        ]);
    }

    public function aiMapped(?AccountHead $head = null, float $confidence = 0.85): static
    {
        return $this->state(fn (array $attributes) => [
            'account_head_id' => $head?->id ?? AccountHead::factory(),
            'mapping_type' => MappingType::Ai,
            'ai_confidence' => $confidence,
        ]);
    }

    public function debit(float $amount = 5000.00): static
    {
        return $this->state(fn (array $attributes) => [
            'debit' => $amount,
            'credit' => null,
        ]);
    }

    public function credit(float $amount = 10000.00): static
    {
        return $this->state(fn (array $attributes) => [
            'debit' => null,
            'credit' => $amount,
        ]);
    }

    public function withRawData(array $data = []): static
    {
        return $this->state(fn (array $attributes) => [
            'raw_data' => $data ?: [
                'original_description' => fake()->sentence(),
                'cheque_number' => fake()->numerify('######'),
            ],
        ]);
    }
}
