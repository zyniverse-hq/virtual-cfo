<?php

use App\Console\Commands\RunScheduledTallyExports;
use App\Jobs\SendScheduledTallyExport;
use App\Models\ScheduledTallyExport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

describe('tally:run-scheduled-exports command', function () {
    it('dispatches jobs for due schedules', function () {
        Queue::fake();

        $schedule = ScheduledTallyExport::factory()->daily()->create([
            'time_of_day' => '10:00',
            'timezone' => 'UTC',
        ]);

        Carbon::setTestNow(Carbon::create(2026, 7, 3, 10, 0, 0, 'UTC'));

        $this->artisan('tally:run-scheduled-exports')
            ->assertExitCode(0);

        Queue::assertPushed(SendScheduledTallyExport::class, function ($job) use ($schedule) {
            return $job->scheduledExport->id === $schedule->id;
        });

        Carbon::setTestNow();
    });

    it('skips inactive schedules', function () {
        Queue::fake();

        ScheduledTallyExport::factory()->inactive()->create([
            'time_of_day' => '10:00',
            'timezone' => 'UTC',
        ]);

        Carbon::setTestNow(Carbon::create(2026, 7, 3, 10, 0, 0, 'UTC'));

        $this->artisan('tally:run-scheduled-exports')
            ->assertExitCode(0);

        Queue::assertNotPushed(SendScheduledTallyExport::class);

        Carbon::setTestNow();
    });

    it('skips schedules that are not due', function () {
        Queue::fake();

        ScheduledTallyExport::factory()->daily()->create([
            'time_of_day' => '10:00',
            'timezone' => 'UTC',
        ]);

        // Run at 14:00 — not due
        Carbon::setTestNow(Carbon::create(2026, 7, 3, 14, 0, 0, 'UTC'));

        $this->artisan('tally:run-scheduled-exports')
            ->assertExitCode(0);

        Queue::assertNotPushed(SendScheduledTallyExport::class);

        Carbon::setTestNow();
    });

    it('dispatches multiple jobs for multiple due schedules', function () {
        Queue::fake();

        ScheduledTallyExport::factory()->daily()->count(3)->create([
            'time_of_day' => '10:00',
            'timezone' => 'UTC',
        ]);

        Carbon::setTestNow(Carbon::create(2026, 7, 3, 10, 0, 0, 'UTC'));

        $this->artisan('tally:run-scheduled-exports')
            ->assertExitCode(0);

        Queue::assertPushed(SendScheduledTallyExport::class, 3);

        Carbon::setTestNow();
    });
});
