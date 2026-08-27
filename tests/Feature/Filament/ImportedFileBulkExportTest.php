<?php

use App\Enums\StatementType;
use App\Filament\Resources\ImportedFileResource\Pages\ListImportedFiles;
use App\Models\AccountHead;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Services\TallyExport\TallyExportService;
use Filament\Notifications\Notification;

use function Pest\Livewire\livewire;

describe('ImportedFileResource bulk Tally export', function () {
    beforeEach(function () {
        asUser();
    });

    it('can bulk export selected invoice files to a single Tally XML file', function () {
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);

        $file1 = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $file2 = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);

        $txn1 = Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file1->id,
            'company_id' => tenant()->id,
            'credit' => '1000.00',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'Buyer Alpha Pvt Ltd',
                'buyer_gstin' => '29AAGCV9545C1ZZ',
                'service_name' => 'Consulting Services',
                'invoice_number' => 'INV-001',
                'invoice_date' => '2026-04-01',
                'base_amount' => 1000.00,
                'total_amount' => 1000.00,
            ],
        ]);

        $txn2 = Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file2->id,
            'company_id' => tenant()->id,
            'credit' => '2000.00',
            'date' => '2026-04-02',
            'raw_data' => [
                'buyer_name' => 'Buyer Beta Pvt Ltd',
                'buyer_gstin' => '27AAGCV9545C1ZZ',
                'service_name' => 'Software Dev',
                'invoice_number' => 'INV-002',
                'invoice_date' => '2026-04-02',
                'base_amount' => 2000.00,
                'total_amount' => 2000.00,
            ],
        ]);

        livewire(ListImportedFiles::class)
            ->callTableBulkAction('export_tally', [$file1, $file2])
            ->assertHasNoTableActionErrors()
            ->assertFileDownloaded();
    });

    it('generates valid multi-voucher XML for transactions across multiple files', function () {
        $head = AccountHead::factory()->create(['company_id' => tenant()->id]);

        $file1 = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $file2 = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);

        $txn1 = Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file1->id,
            'company_id' => tenant()->id,
            'credit' => '1000.00',
            'date' => '2026-04-01',
            'raw_data' => [
                'buyer_name' => 'Buyer Alpha Pvt Ltd',
                'invoice_number' => 'INV-001',
                'base_amount' => 1000.00,
                'total_amount' => 1000.00,
            ],
        ]);

        $txn2 = Transaction::factory()->mapped($head)->create([
            'imported_file_id' => $file2->id,
            'company_id' => tenant()->id,
            'credit' => '2000.00',
            'date' => '2026-04-02',
            'raw_data' => [
                'buyer_name' => 'Buyer Beta Pvt Ltd',
                'invoice_number' => 'INV-002',
                'base_amount' => 2000.00,
                'total_amount' => 2000.00,
            ],
        ]);

        $transactions = Transaction::whereIn('id', [$txn1->id, $txn2->id])
            ->with(['accountHead', 'importedFile.company', 'importedFile.bankAccount'])
            ->get();

        $xml = app(TallyExportService::class)->exportTransactions($transactions);

        expect(substr_count($xml, '<TALLYMESSAGE xmlns:UDF="TallyUDF">'))->toBe(2)
            ->and(substr_count($xml, '<ENVELOPE>'))->toBe(1)
            ->and($xml)->toContain('INV-001')
            ->and($xml)->toContain('INV-002')
            ->and($xml)->toContain('Buyer Alpha Pvt Ltd')
            ->and($xml)->toContain('Buyer Beta Pvt Ltd');
    });

    it('shows a warning notification when selected files have no mapped transactions', function () {
        $file1 = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);
        $file2 = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
            'company_id' => tenant()->id,
        ]);

        // Unmapped transactions
        Transaction::factory()->unmapped()->create([
            'imported_file_id' => $file1->id,
            'company_id' => tenant()->id,
        ]);

        livewire(ListImportedFiles::class)
            ->callTableBulkAction('export_tally', [$file1, $file2])
            ->assertNotified(
                Notification::make()
                    ->warning()
                    ->title('No mapped transactions to export')
            );
    });
});
