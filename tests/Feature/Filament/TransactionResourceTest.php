<?php

use App\Enums\MappingType;
use App\Enums\MatchMethod;
use App\Enums\MatchStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\StatementType;
use App\Filament\Resources\TransactionResource;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Jobs\MatchTransactionHeads;
use App\Models\AccountHead;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\ImportedFile;
use App\Models\ReconciliationMatch;
use App\Models\Transaction;
use App\Services\TallyExport\TallyExportService;
use Illuminate\Support\Facades\Queue;
use Maatwebsite\Excel\Facades\Excel;

use function Pest\Livewire\livewire;

describe('TransactionResource', function () {
    beforeEach(function () {
        asUser();
    });

    it('can render the list page', function () {
        livewire(ListTransactions::class)->assertSuccessful();
    });

    it('can list transactions', function () {
        $transactions = Transaction::factory()->count(3)->create();

        livewire(ListTransactions::class)
            ->assertCanSeeTableRecords($transactions);
    });

    it('can filter by mapping type', function () {
        $head = AccountHead::factory()->create();
        $mapped = Transaction::factory()->mapped($head)->create();
        $unmapped = Transaction::factory()->unmapped()->create();

        livewire(ListTransactions::class)
            ->filterTable('mapping_type', MappingType::Unmapped->value)
            ->assertCanSeeTableRecords([$unmapped])
            ->assertCanNotSeeTableRecords([$mapped]);
    });

    it('can filter by imported file', function () {
        $file1 = ImportedFile::factory()->create();
        $file2 = ImportedFile::factory()->create();
        $t1 = Transaction::factory()->for($file1, 'importedFile')->create();
        $t2 = Transaction::factory()->for($file2, 'importedFile')->create();

        livewire(ListTransactions::class)
            ->filterTable('imported_file_id', $file1->id)
            ->assertCanSeeTableRecords([$t1])
            ->assertCanNotSeeTableRecords([$t2]);
    });

    it('imported file filter options show display_name not original_filename', function () {
        $file = ImportedFile::factory()->create([
            'original_filename' => 'hdfc_cc_jan25.pdf',
            'display_name' => 'HDFC Regalia Jan 2025',
        ]);
        Transaction::factory()->for($file, 'importedFile')->create();

        livewire(ListTransactions::class)
            ->assertSee('HDFC Regalia Jan 2025')
            ->assertDontSee('hdfc_cc_jan25.pdf');
    });

    it('uses Transaction model', function () {
        expect(TransactionResource::getModel())->toBe(Transaction::class);
    });

    it('has a currency column in the table', function () {
        livewire(ListTransactions::class)
            ->assertTableColumnExists('currency');
    });

    it('can bulk assign account head to selected transactions', function () {
        $head = AccountHead::factory()->create();
        $file = ImportedFile::factory()->completed(totalRows: 3, mappedRows: 0)->create();
        $transactions = Transaction::factory()
            ->count(3)
            ->unmapped()
            ->for($file, 'importedFile')
            ->create();

        livewire(ListTransactions::class)
            ->callTableBulkAction('bulk_assign_head', $transactions, [
                'account_head_id' => $head->id,
            ]);

        foreach ($transactions as $transaction) {
            $transaction->refresh();
            expect($transaction->account_head_id)->toBe($head->id)
                ->and($transaction->mapping_type)->toBe(MappingType::Manual)
                ->and($transaction->ai_confidence)->toBeNull();
        }

        // File mapped_rows should be updated
        $file->refresh();
        expect($file->mapped_rows)->toBe(3);
    });

    it('assign head action updates a single transaction to manual mapping', function () {
        $head = AccountHead::factory()->create();
        $file = ImportedFile::factory()->completed(totalRows: 1, mappedRows: 0)->create();
        $transaction = Transaction::factory()
            ->unmapped()
            ->for($file, 'importedFile')
            ->create();

        livewire(ListTransactions::class)
            ->callTableAction('assign_head', $transaction, [
                'account_head_id' => $head->id,
            ]);

        $transaction->refresh();
        expect($transaction->account_head_id)->toBe($head->id)
            ->and($transaction->mapping_type)->toBe(MappingType::Manual)
            ->and($transaction->ai_confidence)->toBeNull();

        // File mapped_rows should be updated
        $file->refresh();
        expect($file->mapped_rows)->toBe(1);
    });

    it('run AI matching header action dispatches MatchTransactionHeads jobs', function () {
        Queue::fake();

        $fileWithUnmapped = ImportedFile::factory()->completed()->create();
        Transaction::factory()
            ->unmapped()
            ->for($fileWithUnmapped, 'importedFile')
            ->create();

        $fileFullyMapped = ImportedFile::factory()->completed()->create();
        Transaction::factory()
            ->mapped()
            ->for($fileFullyMapped, 'importedFile')
            ->create();

        livewire(ListTransactions::class)
            ->callTableAction('run_ai_matching');

        // Job should be dispatched for the file with unmapped transactions
        Queue::assertPushed(MatchTransactionHeads::class, function (MatchTransactionHeads $job) use ($fileWithUnmapped) {
            return $job->importedFile->id === $fileWithUnmapped->id;
        });

        // Job should NOT be dispatched for the file with all mapped transactions
        Queue::assertNotPushed(MatchTransactionHeads::class, function (MatchTransactionHeads $job) use ($fileFullyMapped) {
            return $job->importedFile->id === $fileFullyMapped->id;
        });
    });

    describe('export actions respect active table filters', function () {
        it('tally export exports only transactions matching active account head filter', function () {
            $head = AccountHead::factory()->create();
            $other = AccountHead::factory()->create();

            Transaction::factory()->count(3)->mapped($head)->create(['date' => now()]);
            Transaction::factory()->count(2)->mapped($other)->create(['date' => now()]);

            $capturedTransactions = null;
            $mock = Mockery::mock(TallyExportService::class);
            $mock->shouldReceive('exportTransactions')
                ->once()
                ->andReturnUsing(function ($transactions) use (&$capturedTransactions) {
                    $capturedTransactions = $transactions;

                    return '<?xml version="1.0"?><ENVELOPE></ENVELOPE>';
                });
            app()->instance(TallyExportService::class, $mock);

            livewire(ListTransactions::class)
                ->filterTable('account_head_id', $head->id)
                ->callTableAction('export_tally', data: [
                    'from' => now()->subMonth()->toDateString(),
                    'until' => now()->addDay()->toDateString(),
                ])
                ->assertHasNoTableActionErrors();

            expect($capturedTransactions)->toHaveCount(3)
                ->and($capturedTransactions->pluck('account_head_id')->unique()->first())->toBe($head->id);
        });

        it('csv export action succeeds with active account head filter applied', function () {
            Excel::fake();

            $head = AccountHead::factory()->create();
            $other = AccountHead::factory()->create();

            Transaction::factory()->count(3)->mapped($head)->create();
            Transaction::factory()->count(2)->mapped($other)->create();

            livewire(ListTransactions::class)
                ->filterTable('account_head_id', $head->id)
                ->callTableAction('export_csv', data: ['from' => null, 'until' => null])
                ->assertHasNoTableActionErrors();
        });

        it('excel export action succeeds with active account head filter applied', function () {
            Excel::fake();

            $head = AccountHead::factory()->create();
            $other = AccountHead::factory()->create();

            Transaction::factory()->count(3)->mapped($head)->create();
            Transaction::factory()->count(2)->mapped($other)->create();

            livewire(ListTransactions::class)
                ->filterTable('account_head_id', $head->id)
                ->callTableAction('export_excel', data: ['from' => null, 'until' => null])
                ->assertHasNoTableActionErrors();
        });
    });

    it('can match a bank transaction to an invoice via match_invoice action', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);
        $invoiceFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
        ]);

        $bankTxn = Transaction::factory()->debit(10000.00)->create([
            'imported_file_id' => $bankFile->id,
            'description' => 'NEFT-Vendor Payment',
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);

        $invoiceTxn = Transaction::factory()->debit(10000.00)->create([
            'imported_file_id' => $invoiceFile->id,
            'description' => 'INV/001 - Test Vendor',
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);

        livewire(ListTransactions::class)
            ->callTableAction('match_invoice', $bankTxn, [
                'invoice_transaction_ids' => [$invoiceTxn->id],
            ]);

        expect(ReconciliationMatch::count())->toBe(1);

        $match = ReconciliationMatch::first();
        expect($match->bank_transaction_id)->toBe($bankTxn->id)
            ->and($match->invoice_transaction_id)->toBe($invoiceTxn->id)
            ->and($match->match_method)->toBe(MatchMethod::Manual)
            ->and($match->status)->toBe(MatchStatus::Confirmed);

        $bankTxn->refresh();
        $invoiceTxn->refresh();
        expect($bankTxn->reconciliation_status)->toBe(ReconciliationStatus::Matched)
            ->and($invoiceTxn->reconciliation_status)->toBe(ReconciliationStatus::Matched);
    });

    it('can filter by bank account id using statement type subfilter', function () {
        $bankAccount1 = BankAccount::factory()->create();
        $bankAccount2 = BankAccount::factory()->create();

        $bankFile1 = ImportedFile::factory()->create([
            'statement_type' => StatementType::Bank,
            'bank_account_id' => $bankAccount1->id,
        ]);
        $bankFile2 = ImportedFile::factory()->create([
            'statement_type' => StatementType::Bank,
            'bank_account_id' => $bankAccount2->id,
        ]);
        $t1 = Transaction::factory()->for($bankFile1, 'importedFile')->create();
        $t2 = Transaction::factory()->for($bankFile2, 'importedFile')->create();

        livewire(ListTransactions::class)
            ->filterTable('statement_type', [
                'value' => StatementType::Bank->value,
                'bank_account_id' => $bankAccount1->id,
            ])
            ->assertCanSeeTableRecords([$t1])
            ->assertCanNotSeeTableRecords([$t2]);
    });

    it('can filter by credit card id using statement type subfilter', function () {
        $card1 = CreditCard::factory()->create();
        $card2 = CreditCard::factory()->create();

        $cardFile1 = ImportedFile::factory()->create([
            'statement_type' => StatementType::CreditCard,
            'credit_card_id' => $card1->id,
        ]);
        $cardFile2 = ImportedFile::factory()->create([
            'statement_type' => StatementType::CreditCard,
            'credit_card_id' => $card2->id,
        ]);
        $t1 = Transaction::factory()->for($cardFile1, 'importedFile')->create();
        $t2 = Transaction::factory()->for($cardFile2, 'importedFile')->create();

        livewire(ListTransactions::class)
            ->filterTable('statement_type', [
                'value' => StatementType::CreditCard->value,
                'credit_card_id' => $card1->id,
            ])
            ->assertCanSeeTableRecords([$t1])
            ->assertCanNotSeeTableRecords([$t2]);
    });

    it('normalizeStatementType correctly processes enums and objects', function () {
        $reflection = new ReflectionClass(TransactionResource::class);
        $method = $reflection->getMethod('normalizeStatementType');
        $method->setAccessible(true);

        // Enum
        expect($method->invoke(null, StatementType::Bank))->toBe(StatementType::Bank->value);

        // stdClass with value property (what Filament hydration sometimes passes)
        expect($method->invoke(null, (object) ['value' => StatementType::CreditCard->value]))->toBe(StatementType::CreditCard->value);

        // String
        expect($method->invoke(null, StatementType::Invoice->value))->toBe(StatementType::Invoice->value);

        // Null
        expect($method->invoke(null, null))->toBeNull();
    });
});
