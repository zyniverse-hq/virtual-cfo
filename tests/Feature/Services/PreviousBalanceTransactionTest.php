<?php

use App\Ai\Agents\StatementParser;
use App\Enums\ImportStatus;
use App\Enums\MappingType;
use App\Enums\StatementType;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Services\DocumentProcessor\DocumentProcessor;
use Illuminate\Support\Facades\Storage;

describe('Previous Balance synthetic transaction', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->processor = app(DocumentProcessor::class);
    });

    it('creates a synthetic debit transaction for previous_balance on credit card statements', function () {
        Storage::put('statements/cc.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'ICICI Bank',
                'statement_period' => 'Feb 2026 to Mar 2026',
                'previous_balance' => 65042.08,
                'transactions' => [
                    ['date' => '2026-02-10', 'description' => 'AMAZON PURCHASE', 'debit' => 2000, 'balance' => 67042.08],
                    ['date' => '2026-02-15', 'description' => 'Autodebit Payment Recd.', 'credit' => 65042.08, 'balance' => 2000],
                ],
            ],
        ]);

        $file = ImportedFile::factory()->creditCard()->create([
            'file_path' => 'statements/cc.pdf',
            'original_filename' => 'cc_statement.pdf',
            'status' => ImportStatus::Pending,
        ]);

        $this->processor->process($file);

        $file->refresh();
        expect($file->status)->toBe(ImportStatus::Completed)
            ->and($file->total_rows)->toBe(3); // 2 regular + 1 synthetic

        $synthetic = Transaction::where('imported_file_id', $file->id)
            ->where('is_synthetic', true)
            ->first();

        expect($synthetic)->not->toBeNull()
            ->and($synthetic->description)->toBe('Previous Balance')
            ->and((float) $synthetic->debit)->toBe(65042.08)
            ->and($synthetic->credit)->toBeNull()
            ->and($synthetic->mapping_type)->toBe(MappingType::Unmapped)
            ->and($synthetic->is_synthetic)->toBeTrue();
    });

    it('sets the synthetic transaction date to statement period start date when available', function () {
        Storage::put('statements/cc2.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'ICICI Bank',
                'statement_period' => '2026-02-01 to 2026-03-01',
                'previous_balance' => 10000,
                'transactions' => [
                    ['date' => '2026-02-15', 'description' => 'PURCHASE', 'debit' => 500, 'balance' => 10500],
                ],
            ],
        ]);

        $file = ImportedFile::factory()->creditCard()->create([
            'file_path' => 'statements/cc2.pdf',
            'original_filename' => 'cc2.pdf',
            'status' => ImportStatus::Pending,
        ]);

        $this->processor->process($file);

        $synthetic = Transaction::where('imported_file_id', $file->id)
            ->where('is_synthetic', true)
            ->first();

        expect($synthetic->date->format('Y-m-d'))->toBe('2026-02-01');
    });

    it('falls back to earliest transaction date when statement_period cannot be parsed', function () {
        Storage::put('statements/cc3.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'ICICI Bank',
                'statement_period' => 'Some unparseable period',
                'previous_balance' => 5000,
                'transactions' => [
                    ['date' => '2026-03-10', 'description' => 'PURCHASE B', 'debit' => 100, 'balance' => 5100],
                    ['date' => '2026-03-05', 'description' => 'PURCHASE A', 'debit' => 200, 'balance' => 5200],
                ],
            ],
        ]);

        $file = ImportedFile::factory()->creditCard()->create([
            'file_path' => 'statements/cc3.pdf',
            'original_filename' => 'cc3.pdf',
            'status' => ImportStatus::Pending,
        ]);

        $this->processor->process($file);

        $synthetic = Transaction::where('imported_file_id', $file->id)
            ->where('is_synthetic', true)
            ->first();

        expect($synthetic->date->format('Y-m-d'))->toBe('2026-03-05');
    });

    it('extracts a 2-digit-year slash date from the statement period as the synthetic date', function () {
        Storage::put('statements/cc_slash.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'ICICI Bank',
                'statement_period' => '01/04/24 to 30/04/24',
                'previous_balance' => 3000,
                'transactions' => [
                    ['date' => '2024-04-15', 'description' => 'PURCHASE', 'debit' => 500, 'balance' => 3500],
                ],
            ],
        ]);

        $file = ImportedFile::factory()->creditCard()->create([
            'file_path' => 'statements/cc_slash.pdf',
            'original_filename' => 'cc_slash.pdf',
            'status' => ImportStatus::Pending,
        ]);

        $this->processor->process($file);

        $synthetic = Transaction::where('imported_file_id', $file->id)
            ->where('is_synthetic', true)
            ->first();

        expect($synthetic->date->format('Y-m-d'))->toBe('2024-04-01');
    });

    it('does not read a reference number in the statement period as a date', function () {
        Storage::put('statements/cc_ref.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'ICICI Bank',
                'statement_period' => 'Ref 45-13-24 cleared',
                'previous_balance' => 4000,
                'transactions' => [
                    ['date' => '2026-04-10', 'description' => 'PURCHASE B', 'debit' => 100, 'balance' => 4100],
                    ['date' => '2026-04-05', 'description' => 'PURCHASE A', 'debit' => 200, 'balance' => 4200],
                ],
            ],
        ]);

        $file = ImportedFile::factory()->creditCard()->create([
            'file_path' => 'statements/cc_ref.pdf',
            'original_filename' => 'cc_ref.pdf',
            'status' => ImportStatus::Pending,
        ]);

        $this->processor->process($file);

        $synthetic = Transaction::where('imported_file_id', $file->id)
            ->where('is_synthetic', true)
            ->first();

        expect($synthetic->date->format('Y-m-d'))->toBe('2026-04-05');
    });

    it('ignores a card number followed by a word when extracting the period start date', function () {
        Storage::put('statements/cc_card_number_prose.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'ICICI Bank',
                'statement_period' => 'Card XX1234 Statement 01/04/2024 - 30/04/2024',
                'previous_balance' => 5000,
                'transactions' => [
                    ['date' => '2024-04-15', 'description' => 'PURCHASE', 'debit' => 500, 'balance' => 5500],
                ],
            ],
        ]);

        $file = ImportedFile::factory()->creditCard()->create([
            'file_path' => 'statements/cc_card_number_prose.pdf',
            'original_filename' => 'cc_card_number_prose.pdf',
            'status' => ImportStatus::Pending,
        ]);

        $this->processor->process($file);

        $synthetic = Transaction::where('imported_file_id', $file->id)
            ->where('is_synthetic', true)
            ->first();

        expect($synthetic->date->format('Y-m-d'))->toBe('2024-04-01');
    });

    it('ignores a date-like phrase in surrounding prose in favour of the real period start', function () {
        Storage::put('statements/cc_prose_month.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'ICICI Bank',
                'statement_period' => 'Invoice 12 Mar 99 ref, period 01/04/2024 to 30/04/2024',
                'previous_balance' => 6000,
                'transactions' => [
                    ['date' => '2024-04-15', 'description' => 'PURCHASE', 'debit' => 500, 'balance' => 6500],
                ],
            ],
        ]);

        $file = ImportedFile::factory()->creditCard()->create([
            'file_path' => 'statements/cc_prose_month.pdf',
            'original_filename' => 'cc_prose_month.pdf',
            'status' => ImportStatus::Pending,
        ]);

        $this->processor->process($file);

        $synthetic = Transaction::where('imported_file_id', $file->id)
            ->where('is_synthetic', true)
            ->first();

        expect($synthetic->date->format('Y-m-d'))->toBe('2024-04-01');
    });

    it('does not create a synthetic transaction when previous_balance is zero', function () {
        Storage::put('statements/cc4.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'ICICI Bank',
                'previous_balance' => 0,
                'transactions' => [
                    ['date' => '2026-02-10', 'description' => 'PURCHASE', 'debit' => 500, 'balance' => 500],
                ],
            ],
        ]);

        $file = ImportedFile::factory()->creditCard()->create([
            'file_path' => 'statements/cc4.pdf',
            'original_filename' => 'cc4.pdf',
            'status' => ImportStatus::Pending,
        ]);

        $this->processor->process($file);

        $file->refresh();
        expect($file->total_rows)->toBe(1); // no synthetic

        expect(Transaction::where('imported_file_id', $file->id)->where('is_synthetic', true)->count())->toBe(0);
    });

    it('does not create a synthetic transaction when previous_balance is null', function () {
        Storage::put('statements/cc5.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'ICICI Bank',
                'transactions' => [
                    ['date' => '2026-02-10', 'description' => 'PURCHASE', 'debit' => 500, 'balance' => 500],
                ],
            ],
        ]);

        $file = ImportedFile::factory()->creditCard()->create([
            'file_path' => 'statements/cc5.pdf',
            'original_filename' => 'cc5.pdf',
            'status' => ImportStatus::Pending,
        ]);

        $this->processor->process($file);

        $file->refresh();
        expect($file->total_rows)->toBe(1);

        expect(Transaction::where('imported_file_id', $file->id)->where('is_synthetic', true)->count())->toBe(0);
    });

    it('does not create a synthetic transaction for bank statement imports', function () {
        Storage::put('statements/bank.pdf', 'fake-pdf-content');

        StatementParser::fake([
            [
                'bank_name' => 'HDFC Bank',
                'previous_balance' => 50000,
                'transactions' => [
                    ['date' => '2026-02-10', 'description' => 'SALARY', 'credit' => 80000, 'balance' => 130000],
                ],
            ],
        ]);

        $file = ImportedFile::factory()->create([
            'file_path' => 'statements/bank.pdf',
            'original_filename' => 'bank.pdf',
            'statement_type' => StatementType::Bank,
            'status' => ImportStatus::Pending,
        ]);

        $this->processor->process($file);

        expect(Transaction::where('imported_file_id', $file->id)->where('is_synthetic', true)->count())->toBe(0);
    });

    it('replaces the synthetic transaction on re-processing (idempotent)', function () {
        Storage::put('statements/cc6.pdf', 'fake-pdf-content');

        $response = [
            'bank_name' => 'ICICI Bank',
            'previous_balance' => 65042.08,
            'transactions' => [
                ['date' => '2026-02-10', 'description' => 'PURCHASE', 'debit' => 2000, 'balance' => 67042.08],
            ],
        ];

        StatementParser::fake([$response, $response]);

        $file = ImportedFile::factory()->creditCard()->create([
            'file_path' => 'statements/cc6.pdf',
            'original_filename' => 'cc6.pdf',
            'status' => ImportStatus::Pending,
        ]);

        $this->processor->process($file);

        expect(Transaction::where('imported_file_id', $file->id)->where('is_synthetic', true)->count())->toBe(1);

        // Re-process
        $file->update(['status' => ImportStatus::Pending]);
        $this->processor->process($file);

        // Still only one synthetic transaction
        expect(Transaction::where('imported_file_id', $file->id)->where('is_synthetic', true)->count())->toBe(1);
    });
});
