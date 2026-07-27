<?php

use App\Enums\DateRangeWindow;
use App\Enums\MappingType;
use App\Enums\StatementType;
use App\Jobs\SendScheduledTallyExport;
use App\Mail\TallyExportMail;
use App\Models\AccountHead;
use App\Models\ImportedFile;
use App\Models\ScheduledTallyExport;
use App\Models\Transaction;
use App\Services\TallyExport\TallyExportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

describe('SendScheduledTallyExport job', function () {
    it('sends email with XML attachment when transactions exist', function () {
        Mail::fake();

        $schedule = ScheduledTallyExport::factory()->create([
            'date_range_window' => DateRangeWindow::Previous7Days,
            'recipient_emails' => ['test@example.com'],
        ]);

        $accountHead = AccountHead::factory()->create([
            'company_id' => $schedule->company_id,
        ]);

        $importedFile = ImportedFile::factory()->create([
            'company_id' => $schedule->company_id,
            'statement_type' => StatementType::Bank,
        ]);

        Transaction::factory()->count(3)->create([
            'company_id' => $schedule->company_id,
            'imported_file_id' => $importedFile->id,
            'account_head_id' => $accountHead->id,
            'mapping_type' => MappingType::Manual,
            'date' => Carbon::yesterday(),
        ]);

        Carbon::setTestNow(Carbon::today()->setTime(10, 0));

        (new SendScheduledTallyExport($schedule))->handle(app(TallyExportService::class));

        Mail::assertSent(TallyExportMail::class, function (TallyExportMail $mail) {
            return $mail->hasTo('test@example.com')
                && $mail->transactionCount === 3;
        });

        $schedule->refresh();
        expect($schedule->last_run_status)->toBe('success')
            ->and($schedule->last_run_at)->not->toBeNull();

        Carbon::setTestNow();
    });

    it('updates status to no_data when no transactions match', function () {
        Mail::fake();

        $schedule = ScheduledTallyExport::factory()->create([
            'date_range_window' => DateRangeWindow::Previous7Days,
            'recipient_emails' => ['test@example.com'],
        ]);

        Carbon::setTestNow(Carbon::today()->setTime(10, 0));

        (new SendScheduledTallyExport($schedule))->handle(app(TallyExportService::class));

        Mail::assertNothingSent();

        $schedule->refresh();
        expect($schedule->last_run_status)->toBe('no_data');

        Carbon::setTestNow();
    });

    it('sends to multiple recipients', function () {
        Mail::fake();

        $recipients = ['alice@example.com', 'bob@example.com', 'carol@example.com'];

        $schedule = ScheduledTallyExport::factory()->create([
            'date_range_window' => DateRangeWindow::Previous7Days,
            'recipient_emails' => $recipients,
        ]);

        $accountHead = AccountHead::factory()->create([
            'company_id' => $schedule->company_id,
        ]);

        $importedFile = ImportedFile::factory()->create([
            'company_id' => $schedule->company_id,
        ]);

        Transaction::factory()->create([
            'company_id' => $schedule->company_id,
            'imported_file_id' => $importedFile->id,
            'account_head_id' => $accountHead->id,
            'mapping_type' => MappingType::Manual,
            'date' => Carbon::yesterday(),
        ]);

        Carbon::setTestNow(Carbon::today()->setTime(10, 0));

        (new SendScheduledTallyExport($schedule))->handle(app(TallyExportService::class));

        Mail::assertSent(TallyExportMail::class, 3);

        foreach ($recipients as $email) {
            Mail::assertSent(TallyExportMail::class, fn (TallyExportMail $mail) => $mail->hasTo($email));
        }

        Carbon::setTestNow();
    });

    it('filters by statement type when configured', function () {
        Mail::fake();

        $schedule = ScheduledTallyExport::factory()->create([
            'date_range_window' => DateRangeWindow::Previous7Days,
            'statement_type' => StatementType::Bank,
            'recipient_emails' => ['test@example.com'],
        ]);

        $accountHead = AccountHead::factory()->create([
            'company_id' => $schedule->company_id,
        ]);

        $bankFile = ImportedFile::factory()->create([
            'company_id' => $schedule->company_id,
            'statement_type' => StatementType::Bank,
        ]);

        $invoiceFile = ImportedFile::factory()->create([
            'company_id' => $schedule->company_id,
            'statement_type' => StatementType::Invoice,
        ]);

        // Create 2 bank + 1 invoice transaction
        Transaction::factory()->count(2)->create([
            'company_id' => $schedule->company_id,
            'imported_file_id' => $bankFile->id,
            'account_head_id' => $accountHead->id,
            'mapping_type' => MappingType::Manual,
            'date' => Carbon::yesterday(),
        ]);

        Transaction::factory()->create([
            'company_id' => $schedule->company_id,
            'imported_file_id' => $invoiceFile->id,
            'account_head_id' => $accountHead->id,
            'mapping_type' => MappingType::Manual,
            'date' => Carbon::yesterday(),
        ]);

        Carbon::setTestNow(Carbon::today()->setTime(10, 0));

        (new SendScheduledTallyExport($schedule))->handle(app(TallyExportService::class));

        Mail::assertSent(TallyExportMail::class, function (TallyExportMail $mail) {
            // Only bank transactions should be included
            return $mail->transactionCount === 2;
        });

        Carbon::setTestNow();
    });

    it('records failure status on exception', function () {
        $schedule = ScheduledTallyExport::factory()->create([
            'recipient_emails' => ['test@example.com'],
        ]);

        $job = new SendScheduledTallyExport($schedule);
        $job->failed(new RuntimeException('Test failure'));

        $schedule->refresh();
        expect($schedule->last_run_status)->toBe('failed')
            ->and($schedule->last_run_message)->toBe('Test failure');
    });

    it('only includes mapped transactions', function () {
        Mail::fake();

        $schedule = ScheduledTallyExport::factory()->create([
            'date_range_window' => DateRangeWindow::Previous7Days,
            'recipient_emails' => ['test@example.com'],
        ]);

        $accountHead = AccountHead::factory()->create([
            'company_id' => $schedule->company_id,
        ]);

        $importedFile = ImportedFile::factory()->create([
            'company_id' => $schedule->company_id,
        ]);

        // 1 mapped transaction
        Transaction::factory()->create([
            'company_id' => $schedule->company_id,
            'imported_file_id' => $importedFile->id,
            'account_head_id' => $accountHead->id,
            'mapping_type' => MappingType::Manual,
            'date' => Carbon::yesterday(),
        ]);

        // 1 unmapped transaction (should be excluded)
        Transaction::factory()->create([
            'company_id' => $schedule->company_id,
            'imported_file_id' => $importedFile->id,
            'account_head_id' => null,
            'mapping_type' => MappingType::Unmapped,
            'date' => Carbon::yesterday(),
        ]);

        Carbon::setTestNow(Carbon::today()->setTime(10, 0));

        (new SendScheduledTallyExport($schedule))->handle(app(TallyExportService::class));

        Mail::assertSent(TallyExportMail::class, function (TallyExportMail $mail) {
            return $mail->transactionCount === 1;
        });

        Carbon::setTestNow();
    });
});
