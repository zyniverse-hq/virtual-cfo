<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\ScheduledExportRun;
use App\Models\ScheduledTallyExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledExportRun>
 */
class ScheduledExportRunFactory extends Factory
{
    protected $model = ScheduledExportRun::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'scheduled_tally_export_id' => ScheduledTallyExport::factory(),
            'status' => 'success',
            'transactions_count' => 15,
            'period_start' => now()->subDays(7)->toDateString(),
            'period_end' => now()->subDay()->toDateString(),
            'recipients' => ['cfo@example.com'],
            'error_message' => null,
            'triggered_by' => 'scheduler',
        ];
    }

    public function failed(?string $message = 'Connection timeout'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'transactions_count' => 0,
            'error_message' => $message,
        ]);
    }

    public function noData(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'no_data',
            'transactions_count' => 0,
            'error_message' => 'No mapped transactions found for the configured date range.',
        ]);
    }
}
