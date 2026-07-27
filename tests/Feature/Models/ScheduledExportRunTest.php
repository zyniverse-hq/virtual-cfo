<?php

use App\Jobs\SendScheduledTallyExport;
use App\Models\Company;
use App\Models\ScheduledExportRun;
use App\Models\ScheduledTallyExport;
use App\Services\TallyExport\TallyExportService;

describe('ScheduledExportRun model & job integration', function () {
    it('creates a scheduled export run record when export job executes with no data', function () {
        $company = Company::factory()->create();
        $schedule = ScheduledTallyExport::factory()->create([
            'company_id' => $company->id,
            'recipient_emails' => ['cfo@example.com'],
        ]);

        $job = new SendScheduledTallyExport($schedule, 'scheduler');
        $job->handle(app(TallyExportService::class));

        $runs = ScheduledExportRun::where('scheduled_tally_export_id', $schedule->id)->get();

        expect($runs)->toHaveCount(1)
            ->and($runs->first()->status)->toBe('no_data')
            ->and($runs->first()->transactions_count)->toBe(0)
            ->and($runs->first()->triggered_by)->toBe('scheduler');
    });

    it('creates a manual run record when triggered manually', function () {
        $company = Company::factory()->create();
        $schedule = ScheduledTallyExport::factory()->create([
            'company_id' => $company->id,
            'recipient_emails' => ['cfo@example.com'],
        ]);

        $job = new SendScheduledTallyExport($schedule, 'manual');
        $job->handle(app(TallyExportService::class));

        $run = ScheduledExportRun::where('scheduled_tally_export_id', $schedule->id)->first();

        expect($run)->not->toBeNull()
            ->and($run->triggered_by)->toBe('manual');
    });

    it('links properly to company and scheduled export relationships', function () {
        $run = ScheduledExportRun::factory()->create();

        expect($run->company)->toBeInstanceOf(Company::class)
            ->and($run->scheduledExport)->toBeInstanceOf(ScheduledTallyExport::class);
    });
});
