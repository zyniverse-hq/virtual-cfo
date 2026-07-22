<?php

use App\Ai\Agents\InvoiceParser;
use App\Ai\Agents\StatementParser;
use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Enums\MappingType;
use App\Enums\StatementType;
use App\Jobs\MatchTransactionHeads;
use App\Jobs\ProcessImportedFile;
use App\Jobs\SuggestReconciliationMatches;
use App\Models\AccountHead;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Notifications\ImportFailedNotification;
use App\Services\DocumentProcessor\DocumentProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

describe('ProcessImportedFile auto-suggestion dispatch', function () {
    it('dispatches SuggestReconciliationMatches after successfully processing an invoice file', function () {
        Storage::fake('local');
        Storage::put('statements/invoice.pdf', 'fake-pdf-content');

        InvoiceParser::fake([
            [
                'vendor_name' => 'Test Vendor',
                'invoice_number' => 'INV/001',
                'invoice_date' => '2025-04-05',
                'total_amount' => 15000,
                'currency' => 'INR',
                'base_amount' => 12712,
                'line_items' => [['description' => 'Services', 'amount' => 12712]],
            ],
        ]);

        Queue::fake([SuggestReconciliationMatches::class]);

        $file = ImportedFile::factory()->create([
            'status' => ImportStatus::Pending,
            'statement_type' => StatementType::Invoice,
            'file_path' => 'statements/invoice.pdf',
        ]);

        $job = new ProcessImportedFile($file);
        $job->handle(app(DocumentProcessor::class));

        Queue::assertPushed(SuggestReconciliationMatches::class, function ($job) use ($file) {
            return $job->invoiceFile->id === $file->id;
        });
    });

    it('does not dispatch SuggestReconciliationMatches for bank statement files', function () {
        Storage::fake('local');
        Storage::put('statements/bank.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'HDFC Bank',
                'transactions' => [
                    ['date' => '2025-04-05', 'description' => 'NEFT PAYMENT', 'debit' => 15000, 'balance' => 100000],
                ],
            ],
        ]);

        Queue::fake([SuggestReconciliationMatches::class, MatchTransactionHeads::class]);

        $file = ImportedFile::factory()->create([
            'status' => ImportStatus::Pending,
            'statement_type' => StatementType::Bank,
            'file_path' => 'statements/bank.pdf',
        ]);

        $job = new ProcessImportedFile($file);
        $job->handle(app(DocumentProcessor::class));

        Queue::assertNotPushed(SuggestReconciliationMatches::class);
    });
});

describe('ProcessImportedFile job', function () {
    it('implements ShouldQueue', function () {
        expect(ProcessImportedFile::class)
            ->toImplement(ShouldQueue::class);
    });

    it('sets status to failed with error message on exception', function () {
        $file = ImportedFile::factory()->create(['status' => ImportStatus::Pending]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn ($msg, $ctx) => $msg === 'Failed to process imported file'
                && $ctx['file_id'] === $file->id);

        $job = new ProcessImportedFile($file);

        try {
            $job->handle(app(DocumentProcessor::class));
        } catch (Throwable) {
            // Expected — job rethrows after logging
        }

        $file->refresh();
        expect($file->status)->toBe(ImportStatus::Failed)
            ->and($file->error_message)->not->toBeNull();
    });

    it('can be dispatched', function () {
        Queue::fake();

        $file = ImportedFile::factory()->create();

        ProcessImportedFile::dispatch($file);

        Queue::assertPushed(ProcessImportedFile::class, function ($job) use ($file) {
            return $job->importedFile->id === $file->id;
        });
    });

    it('has exponential backoff configured', function () {
        $file = ImportedFile::factory()->create();
        $job = new ProcessImportedFile($file);

        expect($job->backoff())->toBe([30, 120, 300]);
    });

    it('has 600 second timeout', function () {
        expect((new ProcessImportedFile(ImportedFile::factory()->create()))->timeout)->toBe(600);
    });

    it('has 3 tries configured', function () {
        expect((new ProcessImportedFile(ImportedFile::factory()->create()))->tries)->toBe(3);
    });

    it('marks file as failed on permanent failure', function () {
        $file = ImportedFile::factory()->create(['status' => ImportStatus::Processing]);
        $job = new ProcessImportedFile($file);

        $job->failed(new RuntimeException('Test error'));

        $file->refresh();
        expect($file->status)->toBe(ImportStatus::Failed)
            ->and($file->error_message)->toContain('permanently failed');
    });

    it('does not expose raw SQL in error_message when a database error occurs during handle', function () {
        $file = ImportedFile::factory()->create(['status' => ImportStatus::Pending]);

        $rawSql = 'insert into "transactions" ("company_id", "date") values (4, ?)';
        $dbMessage = "SQLSTATE[23502]: Not null violation: 7 ERROR: null value in column \"date\" (Connection: pgsql, Host: 127.0.0.1, Port: 5432, Database: virtual_cfo, SQL: {$rawSql})";

        $this->mock(DocumentProcessor::class, function ($mock) use ($dbMessage, $rawSql) {
            $mock->shouldReceive('process')
                ->andThrow(new QueryException('pgsql', $rawSql, [], new RuntimeException($dbMessage)));
        });

        Log::shouldReceive('error')->once();

        $job = new ProcessImportedFile($file);

        try {
            $job->handle(app(DocumentProcessor::class));
        } catch (Throwable) {
            // Expected — job rethrows
        }

        $file->refresh();
        expect($file->status)->toBe(ImportStatus::Failed)
            ->and($file->error_message)->not->toContain('SQLSTATE')
            ->and($file->error_message)->not->toContain('127.0.0.1')
            ->and($file->error_message)->not->toContain('insert into');
    });

    it('does not expose raw SQL in error_message on permanent failure with a database error', function () {
        $file = ImportedFile::factory()->create(['status' => ImportStatus::Processing]);
        $job = new ProcessImportedFile($file);

        $rawSql = 'insert into "transactions" ("company_id", "date") values (4, ?)';
        $dbMessage = "SQLSTATE[23502]: Not null violation: 7 ERROR: null value in column \"date\" (Connection: pgsql, Host: 127.0.0.1, Port: 5432, Database: virtual_cfo, SQL: {$rawSql})";
        $dbException = new QueryException('pgsql', $rawSql, [], new RuntimeException($dbMessage));

        $job->failed($dbException);

        $file->refresh();
        expect($file->status)->toBe(ImportStatus::Failed)
            ->and($file->error_message)->not->toContain('SQLSTATE')
            ->and($file->error_message)->not->toContain('127.0.0.1')
            ->and($file->error_message)->not->toContain('insert into');
    });

    it('stores a sanitised error message for any generic exception type', function () {
        $file = ImportedFile::factory()->create(['status' => ImportStatus::Pending]);

        $this->mock(DocumentProcessor::class, function ($mock) {
            $mock->shouldReceive('process')
                ->andThrow(new RuntimeException('Internal system detail: secret config value at /etc/app/config.php line 42'));
        });

        Log::shouldReceive('error')->once();

        $job = new ProcessImportedFile($file);

        try {
            $job->handle(app(DocumentProcessor::class));
        } catch (Throwable) {
            // Expected — job rethrows
        }

        $file->refresh();
        expect($file->status)->toBe(ImportStatus::Failed)
            ->and($file->error_message)->not->toContain('RuntimeException')
            ->and($file->error_message)->not->toContain('/etc/app/config.php')
            ->and($file->error_message)->not->toContain('secret config value');
    });
});

describe('ImportFailedNotification', function () {
    it('does not expose raw DB error text in database notification body', function () {
        $rawSql = 'insert into "transactions" values (?)';
        $sqlState = "SQLSTATE[23502]: Not null violation (SQL: {$rawSql})";

        $file = ImportedFile::factory()->failed($sqlState)->create([
            'original_filename' => 'test.xlsx',
        ]);

        $notification = new ImportFailedNotification($file);
        $notifiable = $file->uploader;

        $databasePayload = $notification->toDatabase($notifiable);

        $body = $databasePayload['body'] ?? '';

        expect($body)->not->toContain('SQLSTATE')
            ->and($body)->not->toContain('insert into');
    });

    it('does not expose raw DB error text in mail notification body', function () {
        $rawSql = 'insert into "transactions" values (?)';
        $sqlState = "SQLSTATE[23502]: Not null violation (SQL: {$rawSql})";

        $file = ImportedFile::factory()->failed($sqlState)->create([
            'original_filename' => 'test.xlsx',
        ]);

        $notification = new ImportFailedNotification($file);
        $notifiable = $file->uploader;

        $mail = $notification->toMail($notifiable);
        $mailLines = implode(' ', $mail->introLines);

        expect($mailLines)->not->toContain('SQLSTATE')
            ->and($mailLines)->not->toContain('insert into');
    });
});

describe('ProcessImportedFile with Agent::fake()', function () {
    beforeEach(function () {
        //
    });

    it('creates transactions and completes file on successful parse', function () {
        Storage::fake('local');
        Storage::put('statements/test.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'HDFC Bank',
                'account_number' => '1234567890',
                'statement_period' => '2024-01-01 to 2024-01-31',
                'transactions' => [
                    ['date' => '2024-01-05', 'description' => 'SALARY JAN 2024', 'credit' => 50000, 'balance' => 150000],
                    ['date' => '2024-01-10', 'description' => 'RENT PAYMENT', 'debit' => 15000, 'balance' => 135000],
                    ['date' => '2024-01-15', 'description' => 'EMI HDFC', 'debit' => 8500, 'balance' => 126500],
                ],
            ],
        ]);

        Queue::fake([MatchTransactionHeads::class]);

        $file = ImportedFile::factory()->create([
            'status' => ImportStatus::Pending,
            'file_path' => 'statements/test.pdf',
        ]);

        $job = new ProcessImportedFile($file);
        $job->handle(app(DocumentProcessor::class));

        $file->refresh();
        expect($file->status)->toBe(ImportStatus::Completed)
            ->and($file->total_rows)->toBe(3)
            ->and($file->mapped_rows)->toBe(0)
            ->and($file->bank_name)->toBe('HDFC Bank')
            ->and($file->account_number)->toBe('1234567890')
            ->and($file->processed_at)->not->toBeNull();

        $transactions = Transaction::where('imported_file_id', $file->id)->get();
        expect($transactions)->toHaveCount(3)
            ->and($transactions->first()->description)->toBe('SALARY JAN 2024')
            ->and($transactions->first()->mapping_type)->toBe(MappingType::Unmapped);
    });

    it('does not auto-dispatch MatchTransactionHeads after processing a bank statement', function () {
        Storage::fake('local');
        Storage::put('statements/test.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'SBI',
                'transactions' => [
                    ['date' => '2024-01-05', 'description' => 'DEPOSIT', 'credit' => 10000, 'balance' => 10000],
                ],
            ],
        ]);

        Queue::fake([MatchTransactionHeads::class]);

        $file = ImportedFile::factory()->create([
            'status' => ImportStatus::Pending,
            'statement_type' => StatementType::Bank,
            'file_path' => 'statements/test.pdf',
        ]);

        AccountHead::factory()->create([
            'company_id' => $file->company_id,
            'is_active' => true,
        ]);

        $job = new ProcessImportedFile($file);
        $job->handle(app(DocumentProcessor::class));

        $file->refresh();
        expect($file->status)->toBe(ImportStatus::Completed);

        Queue::assertNotPushed(MatchTransactionHeads::class);
    });

    it('does not auto-dispatch MatchTransactionHeads after processing a credit card statement', function () {
        Storage::fake('local');
        Storage::put('statements/cc.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'HDFC CC',
                'transactions' => [
                    ['date' => '2024-01-10', 'description' => 'SWIGGY ORDER', 'debit' => 500, 'balance' => 9500],
                ],
            ],
        ]);

        Queue::fake([MatchTransactionHeads::class]);

        $file = ImportedFile::factory()->create([
            'status' => ImportStatus::Pending,
            'statement_type' => StatementType::CreditCard,
            'file_path' => 'statements/cc.pdf',
        ]);

        $job = new ProcessImportedFile($file);
        $job->handle(app(DocumentProcessor::class));

        $file->refresh();
        expect($file->status)->toBe(ImportStatus::Completed);

        Queue::assertNotPushed(MatchTransactionHeads::class);
    });

    it('does not dispatch MatchTransactionHeads for invoice files', function () {
        Storage::fake('local');
        Storage::put('statements/invoice.pdf', 'fake-pdf-content');

        InvoiceParser::fake([
            [
                'vendor_name' => 'Test Vendor',
                'invoice_number' => 'INV/001',
                'invoice_date' => '2025-04-05',
                'total_amount' => 15000,
                'currency' => 'INR',
                'base_amount' => 12712,
                'line_items' => [['description' => 'Services', 'amount' => 12712]],
            ],
        ]);

        Queue::fake([MatchTransactionHeads::class]);

        $file = ImportedFile::factory()->create([
            'status' => ImportStatus::Pending,
            'statement_type' => StatementType::Invoice,
            'file_path' => 'statements/invoice.pdf',
        ]);

        $job = new ProcessImportedFile($file);
        $job->handle(app(DocumentProcessor::class));

        Queue::assertNotPushed(MatchTransactionHeads::class);
    });

    it('marks file as failed when response has empty transactions', function () {
        Storage::fake('local');
        Storage::put('statements/empty.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'ICICI',
                'transactions' => [],
            ],
        ]);

        $file = ImportedFile::factory()->create([
            'status' => ImportStatus::Pending,
            'file_path' => 'statements/empty.pdf',
        ]);

        $job = new ProcessImportedFile($file);
        $job->handle(app(DocumentProcessor::class));

        $file->refresh();
        expect($file->status)->toBe(ImportStatus::Failed)
            ->and($file->error_message)->toContain('No transactions found');
    });

    it('marks file as failed when response is malformed', function () {
        Storage::fake('local');
        Storage::put('statements/bad.pdf', 'fake-pdf-content');

        StatementParser::fake([
            ['bank_name' => 'Unknown Bank'],
        ]);

        $file = ImportedFile::factory()->create([
            'status' => ImportStatus::Pending,
            'file_path' => 'statements/bad.pdf',
        ]);

        Log::shouldReceive('error')->once();
        Log::shouldReceive('warning')->once();

        $job = new ProcessImportedFile($file);

        try {
            $job->handle(app(DocumentProcessor::class));
        } catch (Throwable) {
            // Expected — missing transactions key
        }

        $file->refresh();
        expect($file->status)->toBe(ImportStatus::Failed)
            ->and($file->error_message)->not->toBeNull();
    });
});

describe('ProcessImportedFile invoice display_name', function () {
    it('sets display_name to invoice number, vendor, and description for a manually uploaded invoice', function () {
        Storage::fake('local');
        Storage::put('statements/invoice.pdf', 'fake-pdf-content');

        InvoiceParser::fake([
            [
                'vendor_name' => 'Swiggy',
                'invoice_number' => 'SWG/2025/001',
                'invoice_date' => '2025-04-05',
                'total_amount' => 5000,
                'currency' => 'INR',
                'base_amount' => 4237,
                'line_items' => [['description' => 'Food delivery', 'amount' => 4237]],
            ],
        ]);

        Queue::fake([SuggestReconciliationMatches::class]);

        $file = ImportedFile::factory()->create([
            'status' => ImportStatus::Pending,
            'statement_type' => StatementType::Invoice,
            'source' => ImportSource::ManualUpload,
            'file_path' => 'statements/invoice.pdf',
            'display_name' => null,
        ]);

        $job = new ProcessImportedFile($file);
        $job->handle(app(DocumentProcessor::class));

        $file->refresh();
        expect($file->display_name)->toBe('SWG/2025/001 Swiggy Food delivery');
    });

    it('sets display_name to invoice number, vendor, and description for an invoice imported via email', function () {
        Storage::fake('local');
        Storage::put('statements/invoice.pdf', 'fake-pdf-content');

        InvoiceParser::fake([
            [
                'vendor_name' => 'AWS',
                'invoice_number' => 'AWS/2025/042',
                'invoice_date' => '2025-03-31',
                'total_amount' => 12000,
                'currency' => 'INR',
                'base_amount' => 10169,
                'line_items' => [['description' => 'Cloud services', 'amount' => 10169]],
            ],
        ]);

        Queue::fake([SuggestReconciliationMatches::class]);

        $file = ImportedFile::factory()->create([
            'status' => ImportStatus::Pending,
            'statement_type' => StatementType::Invoice,
            'source' => ImportSource::Email,
            'file_path' => 'statements/invoice.pdf',
            'display_name' => null,
        ]);

        $job = new ProcessImportedFile($file);
        $job->handle(app(DocumentProcessor::class));

        $file->refresh();
        expect($file->display_name)->toBe('AWS/2025/042 AWS Cloud services');
    });
});

describe('ProcessImportedFile email import matching', function () {
    it('does not dispatch MatchTransactionHeads for a bank statement imported via email', function () {
        Storage::fake('local');
        Storage::put('statements/bank.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'HDFC Bank',
                'transactions' => [
                    ['date' => '2025-04-05', 'description' => 'NEFT IN', 'credit' => 50000, 'balance' => 150000],
                ],
            ],
        ]);

        Queue::fake([MatchTransactionHeads::class]);

        $file = ImportedFile::factory()->create([
            'status' => ImportStatus::Pending,
            'statement_type' => StatementType::Bank,
            'source' => ImportSource::Email,
            'file_path' => 'statements/bank.pdf',
        ]);

        $job = new ProcessImportedFile($file);
        $job->handle(app(DocumentProcessor::class));

        Queue::assertNotPushed(MatchTransactionHeads::class);
    });

    it('does not dispatch MatchTransactionHeads for a credit card statement imported via email', function () {
        Storage::fake('local');
        Storage::put('statements/cc.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'HDFC CC',
                'transactions' => [
                    ['date' => '2025-04-10', 'description' => 'ZOMATO', 'debit' => 350, 'balance' => 9650],
                ],
            ],
        ]);

        Queue::fake([MatchTransactionHeads::class]);

        $file = ImportedFile::factory()->create([
            'status' => ImportStatus::Pending,
            'statement_type' => StatementType::CreditCard,
            'source' => ImportSource::Email,
            'file_path' => 'statements/cc.pdf',
        ]);

        $job = new ProcessImportedFile($file);
        $job->handle(app(DocumentProcessor::class));

        Queue::assertNotPushed(MatchTransactionHeads::class);
    });

    it('does not dispatch MatchTransactionHeads for a bank statement uploaded manually', function () {
        Storage::fake('local');
        Storage::put('statements/bank.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'SBI',
                'transactions' => [
                    ['date' => '2025-04-05', 'description' => 'DEPOSIT', 'credit' => 20000, 'balance' => 120000],
                ],
            ],
        ]);

        Queue::fake([MatchTransactionHeads::class]);

        $file = ImportedFile::factory()->create([
            'status' => ImportStatus::Pending,
            'statement_type' => StatementType::Bank,
            'source' => ImportSource::ManualUpload,
            'file_path' => 'statements/bank.pdf',
        ]);

        $job = new ProcessImportedFile($file);
        $job->handle(app(DocumentProcessor::class));

        Queue::assertNotPushed(MatchTransactionHeads::class);
    });
});
