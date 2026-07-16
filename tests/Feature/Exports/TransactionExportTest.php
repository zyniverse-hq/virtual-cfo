<?php

use App\Enums\MappingType;
use App\Exports\TransactionCsvExport;
use App\Exports\TransactionDetailSheet;
use App\Exports\TransactionExcelExport;
use App\Exports\TransactionSummarySheet;
use App\Models\AccountHead;
use App\Models\Company;
use App\Models\ImportedFile;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

describe('export base query filtering', function () {
    beforeEach(function () {
        asUser();
    });

    it('csv export scopes results to base query when provided', function () {
        $head = AccountHead::factory()->create(['name' => 'Office Supplies']);
        $other = AccountHead::factory()->create(['name' => 'Travel']);

        Transaction::factory()->count(3)->mapped($head)->create();
        Transaction::factory()->count(2)->mapped($other)->create();

        $baseQuery = Transaction::query()->where('account_head_id', $head->id);
        $export = new TransactionCsvExport(baseQuery: $baseQuery);

        expect($export->query()->count())->toBe(3);
    });

    it('summary sheet scopes results to base query when provided', function () {
        $head = AccountHead::factory()->create(['name' => 'Office Supplies']);
        $other = AccountHead::factory()->create(['name' => 'Travel']);

        Transaction::factory()->count(3)->mapped($head)->create();
        Transaction::factory()->count(2)->mapped($other)->create();

        $baseQuery = Transaction::query()->where('account_head_id', $head->id);
        $sheet = new TransactionSummarySheet(baseQuery: $baseQuery);
        $data = $sheet->collection();

        expect($data)->toHaveCount(1)
            ->and($data->first()['account_head'])->toBe('Office Supplies');
    });

    it('excel export passes base query to transaction detail sheet', function () {
        $head = AccountHead::factory()->create();
        $other = AccountHead::factory()->create();

        Transaction::factory()->count(3)->mapped($head)->create();
        Transaction::factory()->count(2)->mapped($other)->create();

        $baseQuery = Transaction::query()->where('account_head_id', $head->id);
        $export = new TransactionExcelExport(baseQuery: $baseQuery);
        $sheets = $export->sheets();

        expect($sheets[0]->query()->count())->toBe(3);
    });

    it('csv export still includes all mapped transactions when no base query given', function () {
        $head = AccountHead::factory()->create();
        $other = AccountHead::factory()->create();

        Transaction::factory()->count(3)->mapped($head)->create();
        Transaction::factory()->count(2)->mapped($other)->create();

        $export = new TransactionCsvExport;

        expect($export->query()->count())->toBe(5);
    });
});

describe('TransactionCsvExport', function () {
    beforeEach(function () {
        asUser();
    });

    it('headings use new column order without Mapping Type or Bank/Source', function () {
        $export = new TransactionCsvExport;

        expect($export->headings())->toBe([
            'Date',
            'Reference',
            'Account Head',
            'Debit',
            'Credit',
            'Balance',
            'Currency',
            'Account Head Group',
            'Description',
        ]);
    });

    it('maps transactions in the new column order', function () {
        $head = AccountHead::factory()->create([
            'name' => 'Office Rent',
            'group_name' => 'Indirect Expenses',
        ]);
        $transaction = Transaction::factory()->mapped($head)->debit(5000.50)->create([
            'date' => '2025-03-15',
            'description' => 'NEFT-RENT-PAYMENT',
            'reference_number' => 'REF123',
            'balance' => 45000.00,
            'currency' => 'USD',
        ]);
        $transaction->load(['accountHead', 'importedFile']);

        $export = new TransactionCsvExport;
        $row = $export->map($transaction);

        // New order: Date, Reference, Account Head, Debit, Credit, Balance, Currency, Account Head Group, Description
        expect($row[0])->toBe('15 Mar 2025')       // Date
            ->and($row[1])->toBe('REF123')           // Reference
            ->and($row[2])->toBe('Office Rent')      // Account Head
            ->and((float) $row[3])->toBe(5000.50)    // Debit
            ->and($row[4])->toBeNull()               // Credit
            ->and((float) $row[5])->toBe(45000.00)   // Balance
            ->and($row[6])->toBe('USD')              // Currency
            ->and($row[7])->toBe('Indirect Expenses') // Account Head Group
            ->and($row[8])->toBe('NEFT-RENT-PAYMENT'); // Description
    });

    it('row does not include Mapping Type or Bank/Source columns', function () {
        $head = AccountHead::factory()->create();
        $transaction = Transaction::factory()->mapped($head)->create();
        $transaction->load(['accountHead', 'importedFile']);

        $export = new TransactionCsvExport;
        $row = $export->map($transaction);

        expect(count($row))->toBe(9);
    });

    it('starts at A1 when no importedFile is given', function () {
        $export = new TransactionCsvExport;

        expect($export->startCell())->toBe('A1');
    });

    it('starts at A4 when importedFile is given', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => 'HDFC',
            'account_holder_name' => 'John Doe',
            'statement_period' => 'Apr 2025',
        ]);

        $export = new TransactionCsvExport(importedFile: $file);

        expect($export->startCell())->toBe('A4');
    });

    it('writes metadata header rows to sheet when importedFile is given', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => 'HDFC Bank',
            'account_holder_name' => 'Zysk Technologies',
            'statement_period' => 'Apr 2025',
        ]);

        $head = AccountHead::factory()->create();
        Transaction::factory()->mapped($head)->create(['imported_file_id' => $file->id]);

        $export = new TransactionCsvExport(
            baseQuery: Transaction::where('imported_file_id', $file->id),
            importedFile: $file,
        );

        $path = 'test-exports/transactions-meta.xlsx';
        Excel::store($export, $path, 'local');

        $spreadsheet = IOFactory::load(storage_path("app/private/{$path}"));
        $sheet = $spreadsheet->getActiveSheet();

        expect($sheet->getCell('A1')->getValue())->toBe('Bank:')
            ->and($sheet->getCell('B1')->getValue())->toBe('HDFC Bank')
            ->and($sheet->getCell('A2')->getValue())->toBe('Account Holder:')
            ->and($sheet->getCell('B2')->getValue())->toBe('Zysk Technologies')
            ->and($sheet->getCell('A3')->getValue())->toBe('Statement Period:')
            ->and($sheet->getCell('B3')->getValue())->toBe('Apr 2025')
            ->and($sheet->getCell('A4')->getValue())->toBe('Date'); // headings at row 4

        Storage::disk('local')->delete($path);
    });

    it('respects date range filter', function () {
        $head = AccountHead::factory()->create();
        $inRange = Transaction::factory()->mapped($head)->create(['date' => '2025-03-15']);
        $outOfRange = Transaction::factory()->mapped($head)->create(['date' => '2025-01-01']);

        $export = new TransactionCsvExport(from: '2025-03-01', until: '2025-03-31');
        $results = $export->query()->get();

        expect($results->pluck('id')->toArray())->toContain($inRange->id)
            ->and($results->pluck('id')->toArray())->not->toContain($outOfRange->id);
    });

    it('only includes mapped transactions by default', function () {
        $head = AccountHead::factory()->create();
        $mapped = Transaction::factory()->mapped($head)->create();
        $unmapped = Transaction::factory()->unmapped()->create();

        $export = new TransactionCsvExport;
        $results = $export->query()->get();

        expect($results->pluck('id')->toArray())->toContain($mapped->id)
            ->and($results->pluck('id')->toArray())->not->toContain($unmapped->id);
    });

    it('is tenant-scoped', function () {
        $head = AccountHead::factory()->create();
        $ownTransaction = Transaction::factory()->mapped($head)->create();

        $otherCompany = Company::factory()->create();
        $otherHead = AccountHead::factory()->create();
        DB::table('transactions')->insert([
            'company_id' => $otherCompany->id,
            'imported_file_id' => $ownTransaction->imported_file_id,
            'date' => now(),
            'description' => encrypt('Other company transaction'),
            'debit' => encrypt('1000'),
            'account_head_id' => $otherHead->id,
            'mapping_type' => MappingType::Manual->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherTransactionId = (int) DB::getPdo()->lastInsertId();

        $export = new TransactionCsvExport;
        $results = $export->query()->get();

        expect($results->pluck('id')->toArray())->toContain($ownTransaction->id)
            ->and($results->pluck('id')->toArray())->not->toContain($otherTransactionId);
    });

    it('can be downloaded as CSV', function () {
        Excel::fake();

        $head = AccountHead::factory()->create();
        Transaction::factory()->mapped($head)->create();

        $export = new TransactionCsvExport;
        Excel::download($export, 'transactions.csv');

        Excel::assertDownloaded('transactions.csv');
    });
});

describe('TransactionDetailSheet', function () {
    beforeEach(function () {
        asUser();
    });

    it('writes metadata header block when importedFile is given', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => 'ICICI Bank',
            'account_holder_name' => 'Rahul Sharma',
            'statement_period' => 'Mar 2025',
        ]);

        $head = AccountHead::factory()->create();
        Transaction::factory()->mapped($head)->create(['imported_file_id' => $file->id]);

        $sheet = new TransactionDetailSheet(
            baseQuery: Transaction::where('imported_file_id', $file->id),
            importedFile: $file,
        );

        $path = 'test-exports/detail-sheet-meta.xlsx';
        Excel::store(new TransactionExcelExport(
            baseQuery: Transaction::where('imported_file_id', $file->id),
            importedFile: $file,
        ), $path, 'local');

        $spreadsheet = IOFactory::load(storage_path("app/private/{$path}"));
        $ws = $spreadsheet->getSheetByName('Transactions');

        expect($ws->getCell('A1')->getValue())->toBe('Bank:')
            ->and($ws->getCell('B1')->getValue())->toBe('ICICI Bank')
            ->and($ws->getCell('A2')->getValue())->toBe('Account Holder:')
            ->and($ws->getCell('B2')->getValue())->toBe('Rahul Sharma')
            ->and($ws->getCell('A3')->getValue())->toBe('Statement Period:')
            ->and($ws->getCell('B3')->getValue())->toBe('Mar 2025')
            ->and($ws->getCell('A4')->getValue())->toBe('Date');

        Storage::disk('local')->delete($path);
    });

    it('does not write metadata rows when no importedFile', function () {
        $head = AccountHead::factory()->create();
        Transaction::factory()->mapped($head)->create();

        $path = 'test-exports/detail-sheet-no-meta.xlsx';
        Excel::store(new TransactionExcelExport, $path, 'local');

        $spreadsheet = IOFactory::load(storage_path("app/private/{$path}"));
        $ws = $spreadsheet->getSheetByName('Transactions');

        // Without metadata, headings are at row 1
        expect($ws->getCell('A1')->getValue())->toBe('Date');

        Storage::disk('local')->delete($path);
    });
});

describe('TransactionSummarySheet', function () {
    beforeEach(function () {
        asUser();
    });

    it('appends account holder name to account label', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => 'HDFC',
            'account_holder_name' => 'Test Corp',
        ]);

        $sheet = new TransactionSummarySheet(importedFile: $file);

        expect($sheet->resolveAccountLabel())->toBe('HDFC — Test Corp');
    });

    it('account label with no holder name falls back to bank name only', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => 'Axis Bank',
            'account_holder_name' => null,
        ]);

        $sheet = new TransactionSummarySheet(importedFile: $file);

        expect($sheet->resolveAccountLabel())->toBe('Axis Bank');
    });

    it('starts at A5 when importedFile is given', function () {
        $file = ImportedFile::factory()->create();
        $sheet = new TransactionSummarySheet(importedFile: $file);

        expect($sheet->startCell())->toBe('A5');
    });

    it('starts at A1 when no importedFile is given', function () {
        $sheet = new TransactionSummarySheet;

        expect($sheet->startCell())->toBe('A1');
    });

    it('net amount is never negative even when credit exceeds debit', function () {
        $head = AccountHead::factory()->create(['name' => 'Sales Income']);

        Transaction::factory()->mapped($head)->credit(5000)->create();
        Transaction::factory()->mapped($head)->debit(1000)->create();

        $sheet = new TransactionSummarySheet;
        $data = $sheet->collection();

        $row = $data->firstWhere('account_head', 'Sales Income');

        expect($row)->not->toBeNull()
            ->and((float) $row['net_amount'])->toBe(4000.0)
            ->and((float) $row['net_amount'])->toBeGreaterThanOrEqual(0.0);
    });

    it('summary sheet groups by account head with correct totals', function () {
        $head1 = AccountHead::factory()->create([
            'name' => 'Office Rent',
            'group_name' => 'Indirect Expenses',
        ]);
        $head2 = AccountHead::factory()->create([
            'name' => 'Sales Income',
            'group_name' => 'Direct Income',
        ]);

        Transaction::factory()->mapped($head1)->debit(1000)->create();
        Transaction::factory()->mapped($head1)->debit(2000)->create();
        Transaction::factory()->mapped($head2)->credit(5000)->create();

        $sheet = new TransactionSummarySheet;
        $data = $sheet->collection();

        expect($data)->toHaveCount(2);

        $rentRow = $data->firstWhere('account_head', 'Office Rent');
        $salesRow = $data->firstWhere('account_head', 'Sales Income');

        expect($rentRow)->not->toBeNull()
            ->and((float) $rentRow['total_debit'])->toBe(3000.0)
            ->and((float) $rentRow['total_credit'])->toBe(0.0)
            ->and($salesRow)->not->toBeNull()
            ->and((float) $salesRow['total_debit'])->toBe(0.0)
            ->and((float) $salesRow['total_credit'])->toBe(5000.0);
    });

    it('writes opening balance row to summary sheet', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => 'SBI',
            'account_holder_name' => 'Tech Pvt Ltd',
            'statement_period' => 'Apr 2025',
            'opening_balance' => 10000.00,
        ]);

        $head = AccountHead::factory()->create();
        Transaction::factory()->mapped($head)->create(['imported_file_id' => $file->id]);

        $path = 'test-exports/summary-opening.xlsx';
        Excel::store(new TransactionExcelExport(
            baseQuery: Transaction::where('imported_file_id', $file->id),
            importedFile: $file,
        ), $path, 'local');

        $spreadsheet = IOFactory::load(storage_path("app/private/{$path}"));
        $ws = $spreadsheet->getSheetByName('Summary');

        expect($ws->getCell('A3')->getValue())->toBe('Opening Balance:')
            ->and((float) $ws->getCell('B3')->getValue())->toBe(10000.0);

        Storage::disk('local')->delete($path);
    });

    it('writes closing balance formula row at the bottom of summary sheet', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => 'Kotak',
            'account_holder_name' => null,
            'statement_period' => 'Apr 2025',
            'opening_balance' => 5000.00,
        ]);

        $head = AccountHead::factory()->create();
        Transaction::factory()->mapped($head)->debit(1000)->create(['imported_file_id' => $file->id]);
        Transaction::factory()->mapped($head)->credit(2000)->create(['imported_file_id' => $file->id]);

        $path = 'test-exports/summary-closing.xlsx';
        Excel::store(new TransactionExcelExport(
            baseQuery: Transaction::where('imported_file_id', $file->id),
            importedFile: $file,
        ), $path, 'local');

        $spreadsheet = IOFactory::load(storage_path("app/private/{$path}"));
        $ws = $spreadsheet->getSheetByName('Summary');

        // Find the last row — closing balance should be the very last row
        $lastRow = $ws->getHighestRow();
        expect($ws->getCell("A{$lastRow}")->getValue())->toBe('Closing Balance');

        // The value should equal 5000 + 2000 - 1000 = 6000
        $closingValue = $ws->getCell("B{$lastRow}")->getCalculatedValue();
        expect((float) $closingValue)->toBe(6000.0);

        Storage::disk('local')->delete($path);
    });
});

describe('TransactionExcelExport', function () {
    beforeEach(function () {
        asUser();
    });

    it('has Transactions and Summary sheets', function () {
        $head = AccountHead::factory()->create();
        Transaction::factory()->mapped($head)->create();

        $export = new TransactionExcelExport;
        $sheets = $export->sheets();

        expect($sheets)->toHaveCount(2)
            ->and($sheets[0]->title())->toBe('Transactions')
            ->and($sheets[1]->title())->toBe('Summary');
    });

    it('passes importedFile to the detail sheet', function () {
        $file = ImportedFile::factory()->create();

        $export = new TransactionExcelExport(importedFile: $file);
        $sheets = $export->sheets();

        expect($sheets[0]->importedFile?->id)->toBe($file->id);
    });

    it('can be downloaded as Excel', function () {
        Excel::fake();

        $head = AccountHead::factory()->create();
        Transaction::factory()->mapped($head)->create();

        $export = new TransactionExcelExport;
        Excel::download($export, 'transactions.xlsx');

        Excel::assertDownloaded('transactions.xlsx');
    });
});

describe('ImportedFile new fields', function () {
    it('stores account_holder_name on imported file', function () {
        $file = ImportedFile::factory()->create([
            'account_holder_name' => 'Acme Corp',
        ]);

        expect($file->fresh()->account_holder_name)->toBe('Acme Corp');
    });

    it('stores opening_balance on imported file', function () {
        $file = ImportedFile::factory()->create([
            'opening_balance' => 12500.75,
        ]);

        expect((float) $file->fresh()->opening_balance)->toBe(12500.75);
    });

    it('account_holder_name and opening_balance default to null', function () {
        $file = ImportedFile::factory()->create();

        expect($file->fresh()->account_holder_name)->toBeNull()
            ->and($file->fresh()->opening_balance)->toBeNull();
    });
});
