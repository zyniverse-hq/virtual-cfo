<?php

use App\Enums\MatchMethod;
use App\Enums\MatchStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\StatementType;
use App\Filament\Resources\ReconciliationResource;
use App\Filament\Resources\ReconciliationResource\Pages\ListReconciliation;
use App\Filament\Widgets\ReconciliationStatsOverview;
use App\Jobs\ReconcileImportedFiles;
use App\Models\AccountHead;
use App\Models\ImportedFile;
use App\Models\ReconciliationMatch;
use App\Models\Transaction;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

describe('Reconciliation Page', function () {
    beforeEach(function () {
        asUser();
    });

    it('can render the reconciliation page', function () {
        $this->get(ReconciliationResource::getUrl())
            ->assertSuccessful();
    });

    it('displays summary stats via widget', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);

        Transaction::factory()->count(2)->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Matched,
        ]);
        Transaction::factory()->count(3)->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Flagged,
        ]);
        Transaction::factory()->count(5)->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);

        livewire(ReconciliationStatsOverview::class)
            ->assertSee('Unreconciled')
            ->assertSee('Matched')
            ->assertSee('Flagged')
            ->assertSee('Total Matches');
    });

    it('registers the stats widget as a header widget', function () {
        livewire(ListReconciliation::class)
            ->assertSee('Unreconciled');
    });

    it('displays bank transactions in the table', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);

        $transactions = Transaction::factory()->count(3)->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);

        livewire(ListReconciliation::class)
            ->assertCanSeeTableRecords($transactions)
            ->assertCountTableRecords(3);
    });

    it('excludes invoice transactions from the table', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);
        $invoiceFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
        ]);

        $bankTxn = Transaction::factory()->create([
            'imported_file_id' => $bankFile->id,
        ]);
        $invoiceTxn = Transaction::factory()->create([
            'imported_file_id' => $invoiceFile->id,
        ]);

        livewire(ListReconciliation::class)
            ->assertCanSeeTableRecords([$bankTxn])
            ->assertCanNotSeeTableRecords([$invoiceTxn]);
    });

    it('can filter by reconciliation status', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);

        $unreconciled = Transaction::factory()->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);
        $matched = Transaction::factory()->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Matched,
        ]);
        $flagged = Transaction::factory()->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Flagged,
        ]);

        livewire(ListReconciliation::class)
            ->assertCanSeeTableRecords([$unreconciled, $matched, $flagged])
            ->filterTable('reconciliation_status', ReconciliationStatus::Unreconciled->value)
            ->assertCanSeeTableRecords([$unreconciled])
            ->assertCanNotSeeTableRecords([$matched, $flagged]);
    });

    it('dispatches reconciliation job when run reconciliation action is triggered', function () {
        Queue::fake();

        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);

        $invoiceFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
        ]);

        livewire(ListReconciliation::class)
            ->callTableAction('run_reconciliation', data: [
                'bank_file_id' => $bankFile->id,
                'invoice_file_id' => $invoiceFile->id,
            ]);

        Queue::assertPushed(ReconcileImportedFiles::class, function ($job) use ($bankFile, $invoiceFile) {
            return $job->bankFile->id === $bankFile->id
                && $job->invoiceFile->id === $invoiceFile->id;
        });
    });

    it('can create a manual match with confirmed status', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);
        $invoiceFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
        ]);

        $bankTxn = Transaction::factory()->debit(15000.00)->create([
            'imported_file_id' => $bankFile->id,
            'description' => 'NEFT-Manual Match Test',
            'date' => '2025-04-15',
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);

        $invoiceTxn = Transaction::factory()->debit(15000.00)->create([
            'imported_file_id' => $invoiceFile->id,
            'description' => 'INV/999 - Test Vendor',
            'date' => '2025-04-10',
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
            'raw_data' => ['vendor_name' => 'Test Vendor'],
        ]);

        livewire(ListReconciliation::class)
            ->callTableAction('manual_match', $bankTxn, data: [
                'invoice_transaction_id' => $invoiceTxn->id,
            ]);

        expect(ReconciliationMatch::count())->toBe(1);

        $match = ReconciliationMatch::first();
        expect($match->match_method)->toBe(MatchMethod::Manual)
            ->and($match->confidence)->toBe(1.0)
            ->and($match->status)->toBe(MatchStatus::Confirmed);

        $bankTxn->refresh();
        $invoiceTxn->refresh();
        expect($bankTxn->reconciliation_status)->toBe(ReconciliationStatus::Matched)
            ->and($invoiceTxn->reconciliation_status)->toBe(ReconciliationStatus::Matched);
    });

    it('displays pending suggestions count in stats widget', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);
        $invoiceFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
        ]);

        $bankTxn = Transaction::factory()->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);
        $invoiceTxn = Transaction::factory()->create([
            'imported_file_id' => $invoiceFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);

        ReconciliationMatch::factory()->suggested()->create([
            'bank_transaction_id' => $bankTxn->id,
            'invoice_transaction_id' => $invoiceTxn->id,
        ]);

        livewire(ReconciliationStatsOverview::class)
            ->assertSee('Pending Suggestions');
    });

    it('can confirm a suggested match via table action', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);
        $invoiceFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
        ]);

        $bankTxn = Transaction::factory()->debit(5000.00)->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);

        $invoiceTxn = Transaction::factory()->debit(5000.00)->create([
            'imported_file_id' => $invoiceFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);

        $match = ReconciliationMatch::factory()->suggested()->create([
            'bank_transaction_id' => $bankTxn->id,
            'invoice_transaction_id' => $invoiceTxn->id,
        ]);

        livewire(ListReconciliation::class)
            ->assertTableActionVisible('confirm_suggestion', $bankTxn)
            ->assertTableActionVisible('reject_suggestions', $bankTxn)
            ->callTableAction('confirm_suggestion', $bankTxn);

        $match->refresh();
        $bankTxn->refresh();
        expect($match->status)->toBe(MatchStatus::Confirmed)
            ->and($bankTxn->reconciliation_status)->toBe(ReconciliationStatus::Matched);
    });

    it('hides confirm and reject buttons once the match is no longer suggested', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);
        $invoiceFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
        ]);

        $bankTxn = Transaction::factory()->debit(5000.00)->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Matched,
        ]);

        ReconciliationMatch::factory()->confirmed()->create([
            'bank_transaction_id' => $bankTxn->id,
            'invoice_transaction_id' => Transaction::factory()->create([
                'imported_file_id' => $invoiceFile->id,
            ])->id,
        ]);

        livewire(ListReconciliation::class)
            ->assertTableActionHidden('confirm_suggestion', $bankTxn)
            ->assertTableActionHidden('reject_suggestions', $bankTxn);
    });

    it('can reject all suggestions for a bank transaction via table action', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);
        $invoiceFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
        ]);

        $bankTxn = Transaction::factory()->debit(5000.00)->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);

        $match1 = ReconciliationMatch::factory()->suggested()->create([
            'bank_transaction_id' => $bankTxn->id,
            'invoice_transaction_id' => Transaction::factory()->create([
                'imported_file_id' => $invoiceFile->id,
            ])->id,
        ]);
        $match2 = ReconciliationMatch::factory()->suggested()->create([
            'bank_transaction_id' => $bankTxn->id,
            'invoice_transaction_id' => Transaction::factory()->create([
                'imported_file_id' => $invoiceFile->id,
            ])->id,
        ]);

        livewire(ListReconciliation::class)
            ->callTableAction('reject_suggestions', $bankTxn);

        $match1->refresh();
        $match2->refresh();
        expect($match1->status)->toBe(MatchStatus::Rejected)
            ->and($match2->status)->toBe(MatchStatus::Rejected);
    });

    it('can bulk confirm selected suggested matches', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);
        $invoiceFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
        ]);

        $bankTxn1 = Transaction::factory()->debit(1000.00)->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);
        $bankTxn2 = Transaction::factory()->debit(2000.00)->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);

        $match1 = ReconciliationMatch::factory()->suggested()->create([
            'bank_transaction_id' => $bankTxn1->id,
            'invoice_transaction_id' => Transaction::factory()->create([
                'imported_file_id' => $invoiceFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ])->id,
        ]);
        $match2 = ReconciliationMatch::factory()->suggested()->create([
            'bank_transaction_id' => $bankTxn2->id,
            'invoice_transaction_id' => Transaction::factory()->create([
                'imported_file_id' => $invoiceFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ])->id,
        ]);

        livewire(ListReconciliation::class)
            ->callTableBulkAction('bulk_confirm', [$bankTxn1, $bankTxn2]);

        $match1->refresh();
        $match2->refresh();
        $bankTxn1->refresh();
        $bankTxn2->refresh();

        expect($match1->status)->toBe(MatchStatus::Confirmed)
            ->and($match2->status)->toBe(MatchStatus::Confirmed)
            ->and($bankTxn1->reconciliation_status)->toBe(ReconciliationStatus::Matched)
            ->and($bankTxn2->reconciliation_status)->toBe(ReconciliationStatus::Matched);
    });

    it('bulk confirm skips transactions with no suggested matches', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);
        $invoiceFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
        ]);

        $bankTxnWithMatch = Transaction::factory()->debit(1000.00)->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);
        $bankTxnNoMatch = Transaction::factory()->debit(500.00)->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);

        $match = ReconciliationMatch::factory()->suggested()->create([
            'bank_transaction_id' => $bankTxnWithMatch->id,
            'invoice_transaction_id' => Transaction::factory()->create([
                'imported_file_id' => $invoiceFile->id,
            ])->id,
        ]);

        livewire(ListReconciliation::class)
            ->callTableBulkAction('bulk_confirm', [$bankTxnWithMatch, $bankTxnNoMatch]);

        $match->refresh();
        $bankTxnWithMatch->refresh();
        $bankTxnNoMatch->refresh();

        expect($match->status)->toBe(MatchStatus::Confirmed)
            ->and($bankTxnWithMatch->reconciliation_status)->toBe(ReconciliationStatus::Matched)
            ->and($bankTxnNoMatch->reconciliation_status)->toBe(ReconciliationStatus::Unreconciled);
    });

    it('can bulk reject selected suggested matches', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);
        $invoiceFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
        ]);

        $bankTxn1 = Transaction::factory()->debit(1000.00)->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);
        $bankTxn2 = Transaction::factory()->debit(2000.00)->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);

        $match1 = ReconciliationMatch::factory()->suggested()->create([
            'bank_transaction_id' => $bankTxn1->id,
            'invoice_transaction_id' => Transaction::factory()->create([
                'imported_file_id' => $invoiceFile->id,
            ])->id,
        ]);
        $match2 = ReconciliationMatch::factory()->suggested()->create([
            'bank_transaction_id' => $bankTxn2->id,
            'invoice_transaction_id' => Transaction::factory()->create([
                'imported_file_id' => $invoiceFile->id,
            ])->id,
        ]);

        livewire(ListReconciliation::class)
            ->callTableBulkAction('bulk_reject', [$bankTxn1, $bankTxn2]);

        $match1->refresh();
        $match2->refresh();
        $bankTxn1->refresh();
        $bankTxn2->refresh();

        expect($match1->status)->toBe(MatchStatus::Rejected)
            ->and($match2->status)->toBe(MatchStatus::Rejected)
            ->and($bankTxn1->reconciliation_status)->toBe(ReconciliationStatus::Unreconciled)
            ->and($bankTxn2->reconciliation_status)->toBe(ReconciliationStatus::Unreconciled);
    });

    it('bulk reject rejects all suggestions across multiple transactions', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);
        $invoiceFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Invoice,
        ]);

        $bankTxn = Transaction::factory()->debit(3000.00)->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);

        $match1 = ReconciliationMatch::factory()->suggested()->create([
            'bank_transaction_id' => $bankTxn->id,
            'invoice_transaction_id' => Transaction::factory()->create([
                'imported_file_id' => $invoiceFile->id,
            ])->id,
        ]);
        $match2 = ReconciliationMatch::factory()->suggested()->create([
            'bank_transaction_id' => $bankTxn->id,
            'invoice_transaction_id' => Transaction::factory()->create([
                'imported_file_id' => $invoiceFile->id,
            ])->id,
        ]);

        livewire(ListReconciliation::class)
            ->callTableBulkAction('bulk_reject', [$bankTxn]);

        $match1->refresh();
        $match2->refresh();

        expect($match1->status)->toBe(MatchStatus::Rejected)
            ->and($match2->status)->toBe(MatchStatus::Rejected);
    });
});

describe('Export to Tally action', function () {
    beforeEach(function () {
        asUser();
    });

    it('sends a notification when there are no matched transactions', function () {
        livewire(ListReconciliation::class)
            ->callTableAction('export_tally')
            ->assertNotified('No matched transactions to export');
    });

    it('does not send the empty-state notification when matched transactions exist', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);

        $head = AccountHead::factory()->create(['name' => 'Test Expense']);

        Transaction::factory()->mapped($head)->debit(1000)->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Matched,
        ]);

        livewire(ListReconciliation::class)
            ->callTableAction('export_tally')
            ->assertNotNotified('No matched transactions to export');
    });
});

describe('Description column invoice label', function () {
    beforeEach(function () {
        asUser();
    });

    it('shows invoice vendor and number for a confirmed match', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'company_id' => tenant()->id,
            'statement_type' => StatementType::Bank,
        ]);
        $invoiceFile = ImportedFile::factory()->completed()->create([
            'company_id' => tenant()->id,
            'statement_type' => StatementType::Invoice,
        ]);

        $bankTxn = Transaction::factory()->debit(5000)->create([
            'company_id' => tenant()->id,
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Matched,
        ]);
        $invoiceTxn = Transaction::factory()->create([
            'company_id' => tenant()->id,
            'imported_file_id' => $invoiceFile->id,
            'raw_data' => ['vendor_name' => 'Acme Pvt Ltd', 'invoice_number' => 'INV-2026-042'],
        ]);

        ReconciliationMatch::factory()->confirmed()->create([
            'bank_transaction_id' => $bankTxn->id,
            'invoice_transaction_id' => $invoiceTxn->id,
        ]);

        livewire(ListReconciliation::class)
            ->assertSee('↳ Acme Pvt Ltd · #INV-2026-042');
    });

    it('shows invoice vendor and number for a suggested (pre-confirmation) match', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'company_id' => tenant()->id,
            'statement_type' => StatementType::Bank,
        ]);
        $invoiceFile = ImportedFile::factory()->completed()->create([
            'company_id' => tenant()->id,
            'statement_type' => StatementType::Invoice,
        ]);

        $bankTxn = Transaction::factory()->debit(5000)->create([
            'company_id' => tenant()->id,
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);
        $invoiceTxn = Transaction::factory()->create([
            'company_id' => tenant()->id,
            'imported_file_id' => $invoiceFile->id,
            'raw_data' => ['vendor_name' => 'Beta Corp', 'invoice_number' => 'INV-001'],
        ]);

        ReconciliationMatch::factory()->suggested()->create([
            'bank_transaction_id' => $bankTxn->id,
            'invoice_transaction_id' => $invoiceTxn->id,
        ]);

        livewire(ListReconciliation::class)
            ->assertSee('↳ Beta Corp · #INV-001');
    });
});

describe('Transaction::scopeMatched', function () {
    it('returns only matched transactions', function () {
        $bankFile = ImportedFile::factory()->completed()->create([
            'statement_type' => StatementType::Bank,
        ]);

        $matched = Transaction::factory()->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Matched,
        ]);
        Transaction::factory()->create([
            'imported_file_id' => $bankFile->id,
            'reconciliation_status' => ReconciliationStatus::Unreconciled,
        ]);

        $results = Transaction::matched()->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($matched->id);
    });
});
