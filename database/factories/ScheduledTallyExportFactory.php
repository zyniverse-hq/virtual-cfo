<?php

namespace Database\Factories;

use App\Enums\DateRangeWindow;
use App\Enums\ExportFrequency;
use App\Models\Company;
use App\Models\ScheduledTallyExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledTallyExport>
 */
class ScheduledTallyExportFactory extends Factory
{
    protected $model = ScheduledTallyExport::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'created_by' => User::factory(),
            'frequency' => ExportFrequency::Weekly,
            'day_of_week' => 1, // Monday
            'day_of_month' => null,
            'time_of_day' => '10:00',
            'timezone' => 'Asia/Kolkata',
            'date_range_window' => DateRangeWindow::Previous7Days,
            'statement_type' => null,
            'recipient_emails' => [fake()->safeEmail()],
            'is_active' => true,
        ];
    }

    public function daily(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => ExportFrequency::Daily,
            'day_of_week' => null,
            'day_of_month' => null,
            'date_range_window' => DateRangeWindow::PreviousDay,
        ]);
    }

    public function weekly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => ExportFrequency::Weekly,
            'day_of_week' => fake()->numberBetween(0, 6),
            'day_of_month' => null,
            'date_range_window' => DateRangeWindow::Previous7Days,
        ]);
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => ExportFrequency::Monthly,
            'day_of_week' => null,
            'day_of_month' => fake()->numberBetween(1, 28),
            'date_range_window' => DateRangeWindow::PreviousMonth,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withLastRun(string $status = 'success'): static
    {
        return $this->state(fn (array $attributes) => [
            'last_run_at' => now()->subHours(2),
            'last_run_status' => $status,
            'last_run_message' => $status === 'failed' ? 'Test failure message' : null,
        ]);
    }
}
