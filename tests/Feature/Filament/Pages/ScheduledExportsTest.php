<?php

use App\Enums\DateRangeWindow;
use App\Enums\ExportFrequency;
use App\Filament\Pages\ScheduledExports;
use App\Jobs\SendScheduledTallyExport;
use App\Models\ScheduledExportRun;
use App\Models\ScheduledTallyExport;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

describe('ScheduledExports Filament Page', function () {
    beforeEach(function () {
        asUser();
    });

    it('renders the scheduled exports page successfully', function () {
        livewire(ScheduledExports::class)
            ->assertSuccessful();
    });

    it('can create a scheduled export via form repeater save', function () {
        livewire(ScheduledExports::class)
            ->fillForm([
                'scheduledTallyExports' => [
                    [
                        'frequency' => ExportFrequency::Daily->value,
                        'time_of_day' => '10:00',
                        'timezone' => 'Asia/Kolkata',
                        'date_range_window' => DateRangeWindow::PreviousDay->value,
                        'recipient_emails' => ['finance@example.com'],
                        'is_active' => true,
                    ],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Scheduled exports saved successfully.');

        expect(ScheduledTallyExport::where('company_id', tenant()->id)->count())->toBe(1);

        $schedule = ScheduledTallyExport::where('company_id', tenant()->id)->first();
        expect($schedule->frequency)->toBe(ExportFrequency::Daily)
            ->and($schedule->recipient_emails)->toBe(['finance@example.com']);
    });

    it('dispatches SendScheduledTallyExport job when Test Export Now action is triggered', function () {
        Queue::fake();

        $schedule = ScheduledTallyExport::factory()->create([
            'company_id' => tenant()->id,
            'is_active' => true,
        ]);

        livewire(ScheduledExports::class)
            ->callAction('testExportNow', data: [
                'schedule_id' => $schedule->id,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        Queue::assertPushed(SendScheduledTallyExport::class, function (SendScheduledTallyExport $job) use ($schedule) {
            return $job->scheduledExport->id === $schedule->id
                && $job->triggeredBy === 'manual';
        });
    });

    it('renders export history table rows correctly', function () {
        $schedule = ScheduledTallyExport::factory()->create([
            'company_id' => tenant()->id,
        ]);

        $run = ScheduledExportRun::factory()->create([
            'company_id' => tenant()->id,
            'scheduled_tally_export_id' => $schedule->id,
            'status' => 'success',
            'transactions_count' => 15,
            'recipients' => ['test@example.com'],
            'triggered_by' => 'scheduler',
        ]);

        livewire(ScheduledExports::class)
            ->assertCanSeeTableRecords([$run]);
    });
});
