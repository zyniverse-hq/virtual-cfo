<?php

use App\Enums\DateRangeWindow;
use App\Enums\ExportFrequency;
use App\Models\ScheduledTallyExport;
use Illuminate\Support\Carbon;

describe('DateRangeWindow', function () {
    it('resolves previous day correctly', function () {
        $referenceDate = Carbon::create(2026, 7, 3, 10, 0, 0);
        [$from, $to] = DateRangeWindow::PreviousDay->toDateRange($referenceDate);

        expect($from->toDateString())->toBe('2026-07-02')
            ->and($to->toDateString())->toBe('2026-07-02');
    });

    it('resolves previous 7 days correctly', function () {
        $referenceDate = Carbon::create(2026, 7, 10, 10, 0, 0);
        [$from, $to] = DateRangeWindow::Previous7Days->toDateRange($referenceDate);

        expect($from->toDateString())->toBe('2026-07-03')
            ->and($to->toDateString())->toBe('2026-07-09');
    });

    it('resolves previous month correctly', function () {
        $referenceDate = Carbon::create(2026, 7, 3, 10, 0, 0);
        [$from, $to] = DateRangeWindow::PreviousMonth->toDateRange($referenceDate);

        expect($from->toDateString())->toBe('2026-06-01')
            ->and($to->toDateString())->toBe('2026-06-30');
    });

    it('resolves previous quarter correctly', function () {
        $referenceDate = Carbon::create(2026, 7, 3, 10, 0, 0);
        [$from, $to] = DateRangeWindow::PreviousQuarter->toDateRange($referenceDate);

        expect($from->toDateString())->toBe('2026-04-01')
            ->and($to->toDateString())->toBe('2026-06-30');
    });
});

describe('ScheduledTallyExport isDue', function () {
    it('returns false for inactive schedules', function () {
        $schedule = ScheduledTallyExport::factory()->inactive()->create();

        expect($schedule->isDue())->toBeFalse();
    });

    it('returns true for a daily schedule at the correct time', function () {
        $schedule = ScheduledTallyExport::factory()->daily()->create([
            'time_of_day' => '10:00',
            'timezone' => 'UTC',
        ]);

        $now = Carbon::create(2026, 7, 3, 10, 0, 0, 'UTC');
        expect($schedule->isDue($now))->toBeTrue();
    });

    it('returns false for a daily schedule at the wrong time', function () {
        $schedule = ScheduledTallyExport::factory()->daily()->create([
            'time_of_day' => '10:00',
            'timezone' => 'UTC',
        ]);

        $now = Carbon::create(2026, 7, 3, 14, 0, 0, 'UTC');
        expect($schedule->isDue($now))->toBeFalse();
    });

    it('returns true for a weekly schedule on the correct day and time', function () {
        $schedule = ScheduledTallyExport::factory()->weekly()->create([
            'day_of_week' => 1, // Monday
            'time_of_day' => '10:00',
            'timezone' => 'UTC',
        ]);

        // 2026-07-06 is a Monday
        $now = Carbon::create(2026, 7, 6, 10, 0, 0, 'UTC');
        expect($schedule->isDue($now))->toBeTrue();
    });

    it('returns false for a weekly schedule on the wrong day', function () {
        $schedule = ScheduledTallyExport::factory()->weekly()->create([
            'day_of_week' => 1, // Monday
            'time_of_day' => '10:00',
            'timezone' => 'UTC',
        ]);

        // 2026-07-03 is a Friday
        $now = Carbon::create(2026, 7, 3, 10, 0, 0, 'UTC');
        expect($schedule->isDue($now))->toBeFalse();
    });

    it('returns true for a monthly schedule on the correct day', function () {
        $schedule = ScheduledTallyExport::factory()->monthly()->create([
            'day_of_month' => 5,
            'time_of_day' => '10:00',
            'timezone' => 'UTC',
        ]);

        $now = Carbon::create(2026, 7, 5, 10, 0, 0, 'UTC');
        expect($schedule->isDue($now))->toBeTrue();
    });

    it('returns false for a monthly schedule on the wrong day', function () {
        $schedule = ScheduledTallyExport::factory()->monthly()->create([
            'day_of_month' => 5,
            'time_of_day' => '10:00',
            'timezone' => 'UTC',
        ]);

        $now = Carbon::create(2026, 7, 3, 10, 0, 0, 'UTC');
        expect($schedule->isDue($now))->toBeFalse();
    });

    it('allows a 4-minute tolerance window', function () {
        $schedule = ScheduledTallyExport::factory()->daily()->create([
            'time_of_day' => '10:00',
            'timezone' => 'UTC',
        ]);

        $now = Carbon::create(2026, 7, 3, 10, 3, 0, 'UTC');
        expect($schedule->isDue($now))->toBeTrue();
    });

    it('rejects runs more than 4 minutes past scheduled time', function () {
        $schedule = ScheduledTallyExport::factory()->daily()->create([
            'time_of_day' => '10:00',
            'timezone' => 'UTC',
        ]);

        $now = Carbon::create(2026, 7, 3, 10, 6, 0, 'UTC');
        expect($schedule->isDue($now))->toBeFalse();
    });

    it('prevents double-runs within the same hour', function () {
        $schedule = ScheduledTallyExport::factory()->daily()->create([
            'time_of_day' => '10:00',
            'timezone' => 'UTC',
            'last_run_at' => Carbon::create(2026, 7, 3, 10, 0, 0, 'UTC'),
        ]);

        $now = Carbon::create(2026, 7, 3, 10, 2, 0, 'UTC');
        expect($schedule->isDue($now))->toBeFalse();
    });

    it('respects timezone configuration', function () {
        $schedule = ScheduledTallyExport::factory()->daily()->create([
            'time_of_day' => '10:00',
            'timezone' => 'Asia/Kolkata', // IST = UTC+5:30
        ]);

        // 04:30 UTC = 10:00 IST
        $now = Carbon::create(2026, 7, 3, 4, 30, 0, 'UTC');
        expect($schedule->isDue($now))->toBeTrue();
    });

    it('respects timezone configuration for weekly schedules', function () {
        $schedule = ScheduledTallyExport::factory()->weekly()->create([
            'day_of_week' => 1, // Monday
            'time_of_day' => '10:00',
            'timezone' => 'Asia/Kolkata', // IST = UTC+5:30
        ]);

        // 2026-07-06 is Monday. 04:30 UTC = 10:00 IST
        $now = Carbon::create(2026, 7, 6, 4, 30, 0, 'UTC');
        expect($schedule->isDue($now))->toBeTrue();

        // Wrong day (Tuesday) at same hour should return false
        $wrongDay = Carbon::create(2026, 7, 7, 4, 30, 0, 'UTC');
        expect($schedule->isDue($wrongDay))->toBeFalse();
    });

    it('respects timezone configuration for monthly schedules', function () {
        $schedule = ScheduledTallyExport::factory()->monthly()->create([
            'day_of_month' => 5,
            'time_of_day' => '10:00',
            'timezone' => 'Asia/Kolkata',
        ]);

        // 5th of month at 04:30 UTC = 10:00 IST
        $now = Carbon::create(2026, 7, 5, 4, 30, 0, 'UTC');
        expect($schedule->isDue($now))->toBeTrue();

        // Wrong day of month should return false
        $wrongDay = Carbon::create(2026, 7, 6, 4, 30, 0, 'UTC');
        expect($schedule->isDue($wrongDay))->toBeFalse();
    });

    it('resolves date range via model method', function () {
        $schedule = ScheduledTallyExport::factory()->create([
            'date_range_window' => DateRangeWindow::Previous7Days,
        ]);

        $referenceDate = Carbon::create(2026, 7, 10, 10, 0, 0);
        [$from, $to] = $schedule->resolvedDateRange($referenceDate);

        expect($from->toDateString())->toBe('2026-07-03')
            ->and($to->toDateString())->toBe('2026-07-09');
    });
});

describe('ScheduledTallyExport schedule_description', function () {
    it('describes a daily schedule', function () {
        $schedule = ScheduledTallyExport::factory()->daily()->create([
            'time_of_day' => '10:00',
        ]);

        expect($schedule->schedule_description)->toContain('Daily')
            ->and($schedule->schedule_description)->toContain('10:00 AM');
    });

    it('describes a weekly schedule', function () {
        $schedule = ScheduledTallyExport::factory()->weekly()->create([
            'day_of_week' => 1,
            'time_of_day' => '14:30',
        ]);

        expect($schedule->schedule_description)->toContain('Monday')
            ->and($schedule->schedule_description)->toContain('02:30 PM');
    });

    it('describes a monthly schedule', function () {
        $schedule = ScheduledTallyExport::factory()->monthly()->create([
            'day_of_month' => 15,
            'time_of_day' => '09:00',
        ]);

        expect($schedule->schedule_description)->toContain('Monthly')
            ->and($schedule->schedule_description)->toContain('15')
            ->and($schedule->schedule_description)->toContain('09:00 AM');
    });
});
