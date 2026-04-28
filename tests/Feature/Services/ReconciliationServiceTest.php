<?php

use App\Enums\MatchMethod;
use App\Enums\MatchStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\StatementType;
use App\Models\Company;
use App\Models\ImportedFile;
use App\Models\ReconciliationMatch;
use App\Models\Transaction;
use App\Services\Reconciliation\ReconciliationResult;
use App\Services\Reconciliation\ReconciliationService;

describe('ReconciliationService', function () {
    beforeEach(function () {
        $this->service = new ReconciliationService;
        $this->company = Company::factory()->create();

        // Create a bank statement file and an invoice file for the same company
        $this->bankFile = ImportedFile::factory()->completed(totalRows: 3, mappedRows: 0)->create([
            'company_id' => $this->company->id,
            'statement_type' => StatementType::Bank,
            'bank_name' => 'HDFC',
        ]);

        $this->invoiceFile = ImportedFile::factory()->completed(totalRows: 3, mappedRows: 0)->create([
            'company_id' => $this->company->id,
            'statement_type' => StatementType::Invoice,
            'bank_name' => 'Vendor Invoices',
        ]);
    });

    describe('matchByAmount', function () {
        it('matches transactions with exact same amount', function () {
            $bankTxn = Transaction::factory()->debit(31900.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'NEFT-Assetpro Solution',
                'date' => '2025-04-15',
            ]);

            $invoice = Transaction::factory()->debit(31900.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'ASPL/2439 - Assetpro Solution Pvt Ltd',
                'date' => '2025-04-10',
            ]);

            $invoices = collect([$invoice]);
            $match = $this->service->matchByAmount($bankTxn, $invoices);

            expect($match)->not->toBeNull()
                ->and($match)->toBeInstanceOf(ReconciliationMatch::class)
                ->and($match->bank_transaction_id)->toBe($bankTxn->id)
                ->and($match->invoice_transaction_id)->toBe($invoice->id)
                ->and($match->match_method)->toBe(MatchMethod::Amount)
                ->and($match->confidence)->toBe(1.0);

            // Both transactions should be marked as matched
            $bankTxn->refresh();
            $invoice->refresh();
            expect($bankTxn->reconciliation_status)->toBe(ReconciliationStatus::Matched)
                ->and($invoice->reconciliation_status)->toBe(ReconciliationStatus::Matched);
        });

        it('matches transactions within tolerance', function () {
            $bankTxn = Transaction::factory()->debit(31900.50)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'NEFT-Assetpro',
                'date' => '2025-04-15',
            ]);

            $invoice = Transaction::factory()->debit(31900.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'ASPL/2439',
                'date' => '2025-04-10',
            ]);

            $match = $this->service->matchByAmount($bankTxn, collect([$invoice]), tolerance: 1.0);

            expect($match)->not->toBeNull()
                ->and($match->confidence)->toBeGreaterThanOrEqual(0.9);
        });

        it('does not match when amount difference exceeds tolerance', function () {
            $bankTxn = Transaction::factory()->debit(31900.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'NEFT-Assetpro',
                'date' => '2025-04-15',
            ]);

            $invoice = Transaction::factory()->debit(32000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'ASPL/2439',
                'date' => '2025-04-10',
            ]);

            $match = $this->service->matchByAmount($bankTxn, collect([$invoice]), tolerance: 1.0);

            expect($match)->toBeNull();
        });

        it('returns null when bank transaction has no amount', function () {
            $bankTxn = Transaction::factory()->create([
                'imported_file_id' => $this->bankFile->id,
                'debit' => null,
                'credit' => null,
                'date' => '2025-04-15',
            ]);

            $invoice = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
            ]);

            $match = $this->service->matchByAmount($bankTxn, collect([$invoice]));

            expect($match)->toBeNull();
        });

        it('matches the first invoice with matching amount', function () {
            $bankTxn = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'Payment',
                'date' => '2025-04-15',
            ]);

            $invoice1 = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Invoice 1',
                'date' => '2025-04-10',
            ]);

            $invoice2 = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Invoice 2',
                'date' => '2025-04-05',
            ]);

            $match = $this->service->matchByAmount($bankTxn, collect([$invoice1, $invoice2]));

            expect($match)->not->toBeNull()
                ->and($match->invoice_transaction_id)->toBe($invoice1->id);
        });
    });

    describe('matchByAmountAndDate', function () {
        it('matches when amount matches and date is within window', function () {
            $bankTxn = Transaction::factory()->debit(15000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'RTGS-Vendor Payment',
                'date' => '2025-04-20',
            ]);

            $invoice = Transaction::factory()->debit(15000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'INV/001 - Vendor Corp',
                'date' => '2025-04-10',
            ]);

            $match = $this->service->matchByAmountAndDate($bankTxn, collect([$invoice]));

            expect($match)->not->toBeNull()
                ->and($match->match_method)->toBe(MatchMethod::AmountDate)
                ->and($match->confidence)->toBeGreaterThan(0.5);
        });

        it('does not match when payment is before invoice date', function () {
            $bankTxn = Transaction::factory()->debit(15000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'Payment',
                'date' => '2025-04-01',
            ]);

            $invoice = Transaction::factory()->debit(15000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Invoice',
                'date' => '2025-04-10',
            ]);

            $match = $this->service->matchByAmountAndDate($bankTxn, collect([$invoice]));

            expect($match)->toBeNull();
        });

        it('does not match when date is outside the window', function () {
            $bankTxn = Transaction::factory()->debit(15000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'Payment',
                'date' => '2025-07-15',
            ]);

            $invoice = Transaction::factory()->debit(15000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Invoice',
                'date' => '2025-04-10',
            ]);

            $match = $this->service->matchByAmountAndDate(
                $bankTxn,
                collect([$invoice]),
                dayWindow: 60,
            );

            expect($match)->toBeNull();
        });

        it('picks the closest date match among multiple candidates', function () {
            $bankTxn = Transaction::factory()->debit(10000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'Payment',
                'date' => '2025-04-20',
            ]);

            $invoiceFar = Transaction::factory()->debit(10000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Invoice Far',
                'date' => '2025-03-01',
            ]);

            $invoiceClose = Transaction::factory()->debit(10000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Invoice Close',
                'date' => '2025-04-18',
            ]);

            $match = $this->service->matchByAmountAndDate(
                $bankTxn,
                collect([$invoiceFar, $invoiceClose]),
            );

            expect($match)->not->toBeNull()
                ->and($match->invoice_transaction_id)->toBe($invoiceClose->id);
        });

        it('requires amount match within tolerance even with date proximity', function () {
            $bankTxn = Transaction::factory()->debit(15000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'Payment',
                'date' => '2025-04-20',
            ]);

            $invoice = Transaction::factory()->debit(20000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Invoice',
                'date' => '2025-04-18',
            ]);

            $match = $this->service->matchByAmountAndDate($bankTxn, collect([$invoice]));

            expect($match)->toBeNull();
        });
    });

    describe('matchByPartyName', function () {
        it('matches when amount matches and party name is similar', function () {
            $bankTxn = Transaction::factory()->debit(27500.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'NEFT-Assetpro Solution',
                'date' => '2025-04-15',
            ]);

            $invoice = Transaction::factory()->debit(27500.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'ASPL/2439 - Assetpro Solution Pvt Ltd',
                'date' => '2025-04-10',
                'raw_data' => [
                    'vendor_name' => 'Assetpro Solution Pvt Ltd',
                    'invoice_number' => 'ASPL/2439',
                ],
            ]);

            $match = $this->service->matchByPartyName($bankTxn, collect([$invoice]));

            expect($match)->not->toBeNull()
                ->and($match->match_method)->toBe(MatchMethod::AmountDateParty);
        });

        it('uses vendor_name from raw_data for matching', function () {
            $bankTxn = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'RTGS-CloudTech Services',
                'date' => '2025-04-20',
            ]);

            $invoice = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'CT/100',
                'date' => '2025-04-15',
                'raw_data' => [
                    'vendor_name' => 'CloudTech Services Private Limited',
                    'invoice_number' => 'CT/100',
                ],
            ]);

            $match = $this->service->matchByPartyName($bankTxn, collect([$invoice]));

            expect($match)->not->toBeNull();
        });

        it('does not match when party names are completely different', function () {
            $bankTxn = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'NEFT-Amazon Web Services',
                'date' => '2025-04-15',
            ]);

            $invoice = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Invoice from Zomato',
                'date' => '2025-04-10',
                'raw_data' => [
                    'vendor_name' => 'Zomato Limited',
                ],
            ]);

            $match = $this->service->matchByPartyName($bankTxn, collect([$invoice]));

            expect($match)->toBeNull();
        });

        it('requires amount match even with name similarity', function () {
            $bankTxn = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'NEFT-Assetpro Solution',
                'date' => '2025-04-15',
            ]);

            $invoice = Transaction::factory()->debit(50000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Assetpro Solution Pvt Ltd',
                'date' => '2025-04-10',
            ]);

            $match = $this->service->matchByPartyName($bankTxn, collect([$invoice]));

            expect($match)->toBeNull();
        });
    });

    describe('reconcile', function () {
        it('matches bank transactions against invoices using cascading strategies', function () {
            // Exact amount match
            Transaction::factory()->debit(31900.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'NEFT-Payment 1',
                'date' => '2025-04-15',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);
            Transaction::factory()->debit(31900.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Invoice 1',
                'date' => '2025-04-10',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            // Amount + date match
            Transaction::factory()->debit(15000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'RTGS-Payment 2',
                'date' => '2025-04-20',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);
            Transaction::factory()->debit(15000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Invoice 2',
                'date' => '2025-04-18',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $result = $this->service->reconcile($this->bankFile, $this->invoiceFile);

            expect($result)->toBeInstanceOf(ReconciliationResult::class)
                ->and($result->matched)->toBe(2);

            // Verify reconciliation matches were created
            expect(ReconciliationMatch::count())->toBe(2);
        });

        it('flags unmatched bank transactions', function () {
            // Bank transaction with no matching invoice
            $bankTxn = Transaction::factory()->debit(999.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'Bank Charge',
                'date' => '2025-04-15',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $result = $this->service->reconcile($this->bankFile, $this->invoiceFile);

            $bankTxn->refresh();
            expect($bankTxn->reconciliation_status)->toBe(ReconciliationStatus::Flagged)
                ->and($result->flagged)->toBeGreaterThanOrEqual(1);
        });

        it('flags unmatched invoices', function () {
            // Invoice with no matching bank transaction
            $invoice = Transaction::factory()->debit(50000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Unpaid Invoice',
                'date' => '2025-04-10',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $result = $this->service->reconcile($this->bankFile, $this->invoiceFile);

            $invoice->refresh();
            expect($invoice->reconciliation_status)->toBe(ReconciliationStatus::Flagged);
        });

        it('handles empty transaction sets gracefully', function () {
            $result = $this->service->reconcile($this->bankFile, $this->invoiceFile);

            expect($result)->toBeInstanceOf(ReconciliationResult::class)
                ->and($result->matched)->toBe(0);
        });

        it('does not double-match the same invoice to multiple bank transactions', function () {
            // Two bank transactions with same amount
            Transaction::factory()->debit(10000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'Payment 1',
                'date' => '2025-04-15',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);
            Transaction::factory()->debit(10000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'Payment 2',
                'date' => '2025-04-16',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            // Only one invoice with that amount
            Transaction::factory()->debit(10000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Single Invoice',
                'date' => '2025-04-10',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $result = $this->service->reconcile($this->bankFile, $this->invoiceFile);

            // Only one match should be created
            expect($result->matched)->toBe(1)
                ->and(ReconciliationMatch::count())->toBe(1);
        });
    });

    describe('flagUnmatched', function () {
        it('flags all unreconciled transactions in both files', function () {
            $bankTxn = Transaction::factory()->create([
                'imported_file_id' => $this->bankFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);
            $invoiceTxn = Transaction::factory()->create([
                'imported_file_id' => $this->invoiceFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            // This one is already matched, should not be flagged
            $matchedTxn = Transaction::factory()->create([
                'imported_file_id' => $this->bankFile->id,
                'reconciliation_status' => ReconciliationStatus::Matched,
            ]);

            $this->service->flagUnmatched($this->bankFile, $this->invoiceFile);

            $bankTxn->refresh();
            $invoiceTxn->refresh();
            $matchedTxn->refresh();

            expect($bankTxn->reconciliation_status)->toBe(ReconciliationStatus::Flagged)
                ->and($invoiceTxn->reconciliation_status)->toBe(ReconciliationStatus::Flagged)
                ->and($matchedTxn->reconciliation_status)->toBe(ReconciliationStatus::Matched);
        });
    });

    describe('enrichMatchedTransactions', function () {
        it('enriches bank transaction raw_data with invoice details', function () {
            $bankTxn = Transaction::factory()->debit(31900.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'NEFT-Assetpro',
                'date' => '2025-04-15',
                'reconciliation_status' => ReconciliationStatus::Matched,
                'raw_data' => ['original' => 'bank data'],
            ]);

            $invoiceTxn = Transaction::factory()->debit(31900.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'ASPL/2439',
                'date' => '2025-04-10',
                'reference_number' => 'ASPL/2439',
                'reconciliation_status' => ReconciliationStatus::Matched,
                'raw_data' => [
                    'vendor_name' => 'Assetpro Solution Pvt Ltd',
                    'vendor_gstin' => '29AAQCA1895C1ZD',
                    'invoice_number' => 'ASPL/2439',
                    'base_amount' => 27500.00,
                    'cgst_amount' => 2475.00,
                    'sgst_amount' => 2475.00,
                    'tds_amount' => 550.00,
                    'line_items' => [
                        ['description' => 'Service', 'amount' => 27500.00],
                    ],
                ],
            ]);

            ReconciliationMatch::factory()->create([
                'bank_transaction_id' => $bankTxn->id,
                'invoice_transaction_id' => $invoiceTxn->id,
                'match_method' => MatchMethod::Amount,
                'confidence' => 1.0,
            ]);

            $this->service->enrichMatchedTransactions($this->bankFile);

            $bankTxn->refresh();
            $rawData = $bankTxn->raw_data;

            expect($rawData)->toHaveKey('original', 'bank data')
                ->and($rawData)->toHaveKey('reconciled_invoice_id', $invoiceTxn->id)
                ->and($rawData)->toHaveKey('vendor_name', 'Assetpro Solution Pvt Ltd')
                ->and($rawData)->toHaveKey('vendor_gstin', '29AAQCA1895C1ZD')
                ->and($rawData)->toHaveKey('invoice_number', 'ASPL/2439')
                ->and($rawData)->toHaveKey('base_amount', 27500.00)
                ->and($rawData)->toHaveKey('cgst_amount', 2475.00)
                ->and($rawData)->toHaveKey('sgst_amount', 2475.00)
                ->and($rawData)->toHaveKey('tds_amount', 550.00)
                ->and($rawData)->toHaveKey('line_items');
        });

        it('preserves existing raw_data when enriching', function () {
            $bankTxn = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'reconciliation_status' => ReconciliationStatus::Matched,
                'raw_data' => ['existing_key' => 'existing_value', 'cheque_number' => '123456'],
            ]);

            $invoiceTxn = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'reconciliation_status' => ReconciliationStatus::Matched,
                'raw_data' => ['vendor_name' => 'Test Vendor'],
            ]);

            ReconciliationMatch::factory()->create([
                'bank_transaction_id' => $bankTxn->id,
                'invoice_transaction_id' => $invoiceTxn->id,
            ]);

            $this->service->enrichMatchedTransactions($this->bankFile);

            $bankTxn->refresh();
            expect($bankTxn->raw_data)->toHaveKey('existing_key', 'existing_value')
                ->and($bankTxn->raw_data)->toHaveKey('cheque_number', '123456')
                ->and($bankTxn->raw_data)->toHaveKey('vendor_name', 'Test Vendor');
        });

        it('skips transactions without matches', function () {
            $bankTxn = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'reconciliation_status' => ReconciliationStatus::Matched,
                'raw_data' => ['original' => 'data'],
            ]);

            // No ReconciliationMatch created

            $this->service->enrichMatchedTransactions($this->bankFile);

            $bankTxn->refresh();
            expect($bankTxn->raw_data)->toBe(['original' => 'data']);
        });
    });

    describe('ReconciliationResult', function () {
        it('calculates total correctly', function () {
            $result = new ReconciliationResult;
            $result->matched = 5;
            $result->partiallyMatched = 2;
            $result->flagged = 3;
            $result->unreconciled = 1;

            expect($result->total())->toBe(11);
        });
    });

    describe('findCandidates', function () {
        it('returns ranked candidates without creating match records', function () {
            $bankTxn = Transaction::factory()->debit(31900.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'NEFT-Assetpro Solution',
                'date' => '2025-04-15',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $invoice1 = Transaction::factory()->debit(31900.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'ASPL/2439 - Assetpro Solution Pvt Ltd',
                'date' => '2025-04-10',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $invoice2 = Transaction::factory()->debit(31900.50)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Other Invoice',
                'date' => '2025-04-12',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $candidates = $this->service->findCandidates($bankTxn, collect([$invoice1, $invoice2]));

            expect($candidates)->toHaveCount(2)
                ->and($candidates[0]['invoice']->id)->toBe($invoice1->id)
                ->and($candidates[0]['confidence'])->toBeGreaterThan($candidates[1]['confidence'])
                ->and($candidates[0]['method'])->toBeInstanceOf(MatchMethod::class);

            // No match records should be created
            expect(ReconciliationMatch::count())->toBe(0);
        });

        it('returns empty collection when no candidates match', function () {
            $bankTxn = Transaction::factory()->debit(999.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'description' => 'Unique Payment',
                'date' => '2025-04-15',
            ]);

            $invoice = Transaction::factory()->debit(50000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Different Invoice',
                'date' => '2025-04-10',
            ]);

            $candidates = $this->service->findCandidates($bankTxn, collect([$invoice]));

            expect($candidates)->toBeEmpty();
        });
    });

    describe('suggestMatches', function () {
        it('creates matches with suggested status', function () {
            Transaction::factory()->debit(31900.00)->create([
                'company_id' => $this->company->id,
                'imported_file_id' => $this->bankFile->id,
                'description' => 'NEFT-Assetpro Solution',
                'date' => '2025-04-15',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            Transaction::factory()->debit(31900.00)->create([
                'company_id' => $this->company->id,
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'ASPL/2439',
                'date' => '2025-04-10',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $count = $this->service->suggestMatches($this->invoiceFile);

            expect($count)->toBe(1);

            $match = ReconciliationMatch::first();
            expect($match->status)->toBe(MatchStatus::Suggested);
        });

        it('does not update transaction reconciliation status for suggestions', function () {
            $bankTxn = Transaction::factory()->debit(5000.00)->create([
                'company_id' => $this->company->id,
                'imported_file_id' => $this->bankFile->id,
                'description' => 'Payment',
                'date' => '2025-04-15',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            Transaction::factory()->debit(5000.00)->create([
                'company_id' => $this->company->id,
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Invoice',
                'date' => '2025-04-10',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $this->service->suggestMatches($this->invoiceFile);

            $bankTxn->refresh();
            expect($bankTxn->reconciliation_status)->toBe(ReconciliationStatus::Unreconciled);
        });

        it('skips invoices that already have suggested matches', function () {
            Transaction::factory()->debit(5000.00)->create([
                'company_id' => $this->company->id,
                'imported_file_id' => $this->bankFile->id,
                'description' => 'Payment',
                'date' => '2025-04-15',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            Transaction::factory()->debit(5000.00)->create([
                'company_id' => $this->company->id,
                'imported_file_id' => $this->invoiceFile->id,
                'description' => 'Invoice',
                'date' => '2025-04-10',
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            // First run creates suggestions
            $this->service->suggestMatches($this->invoiceFile);
            expect(ReconciliationMatch::count())->toBe(1);

            // Second run should not create duplicates
            $this->service->suggestMatches($this->invoiceFile);
            expect(ReconciliationMatch::count())->toBe(1);
        });
    });

    describe('confirmSuggestion', function () {
        it('updates match status to confirmed', function () {
            $bankTxn = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $invoiceTxn = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $match = ReconciliationMatch::factory()->suggested()->create([
                'bank_transaction_id' => $bankTxn->id,
                'invoice_transaction_id' => $invoiceTxn->id,
            ]);

            $this->service->confirmSuggestion($match);

            $match->refresh();
            expect($match->status)->toBe(MatchStatus::Confirmed);
        });

        it('updates both transaction statuses to matched', function () {
            $bankTxn = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $invoiceTxn = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $match = ReconciliationMatch::factory()->suggested()->create([
                'bank_transaction_id' => $bankTxn->id,
                'invoice_transaction_id' => $invoiceTxn->id,
            ]);

            $this->service->confirmSuggestion($match);

            $bankTxn->refresh();
            $invoiceTxn->refresh();
            expect($bankTxn->reconciliation_status)->toBe(ReconciliationStatus::Matched)
                ->and($invoiceTxn->reconciliation_status)->toBe(ReconciliationStatus::Matched);
        });

        it('rejects other suggestions for the same bank transaction', function () {
            $bankTxn = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $invoice1 = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $invoice2 = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $match1 = ReconciliationMatch::factory()->suggested()->create([
                'bank_transaction_id' => $bankTxn->id,
                'invoice_transaction_id' => $invoice1->id,
            ]);

            $match2 = ReconciliationMatch::factory()->suggested()->create([
                'bank_transaction_id' => $bankTxn->id,
                'invoice_transaction_id' => $invoice2->id,
            ]);

            $this->service->confirmSuggestion($match1);

            $match2->refresh();
            expect($match2->status)->toBe(MatchStatus::Rejected);
        });

        it('rejects other suggestions for the same invoice transaction', function () {
            $bankTxn1 = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $bankTxn2 = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->bankFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $invoice = Transaction::factory()->debit(5000.00)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $match1 = ReconciliationMatch::factory()->suggested()->create([
                'bank_transaction_id' => $bankTxn1->id,
                'invoice_transaction_id' => $invoice->id,
            ]);

            $match2 = ReconciliationMatch::factory()->suggested()->create([
                'bank_transaction_id' => $bankTxn2->id,
                'invoice_transaction_id' => $invoice->id,
            ]);

            $this->service->confirmSuggestion($match1);

            $match2->refresh();
            expect($match2->status)->toBe(MatchStatus::Rejected);
        });
    });

    describe('rejectSuggestion', function () {
        it('updates match status to rejected', function () {
            $match = ReconciliationMatch::factory()->suggested()->create([
                'bank_transaction_id' => Transaction::factory()->create([
                    'imported_file_id' => $this->bankFile->id,
                ])->id,
                'invoice_transaction_id' => Transaction::factory()->create([
                    'imported_file_id' => $this->invoiceFile->id,
                ])->id,
            ]);

            $this->service->rejectSuggestion($match);

            $match->refresh();
            expect($match->status)->toBe(MatchStatus::Rejected);
        });

        it('stores rejection reason in notes', function () {
            $match = ReconciliationMatch::factory()->suggested()->create([
                'bank_transaction_id' => Transaction::factory()->create([
                    'imported_file_id' => $this->bankFile->id,
                ])->id,
                'invoice_transaction_id' => Transaction::factory()->create([
                    'imported_file_id' => $this->invoiceFile->id,
                ])->id,
            ]);

            $this->service->rejectSuggestion($match, 'Wrong vendor');

            $match->refresh();
            expect($match->status)->toBe(MatchStatus::Rejected)
                ->and($match->notes)->toBe('Wrong vendor');
        });
    });

    describe('rejectAllSuggestions', function () {
        it('rejects a confirmed match and reverts both transactions to unreconciled', function () {
            $bankTxn = Transaction::factory()->debit(5000)->create([
                'imported_file_id' => $this->bankFile->id,
                'reconciliation_status' => ReconciliationStatus::Matched,
            ]);
            $invoiceTxn = Transaction::factory()->debit(5000)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'reconciliation_status' => ReconciliationStatus::Matched,
            ]);

            $match = ReconciliationMatch::factory()->confirmed()->create([
                'bank_transaction_id' => $bankTxn->id,
                'invoice_transaction_id' => $invoiceTxn->id,
            ]);

            $count = $this->service->rejectAllSuggestions($bankTxn);

            $match->refresh();
            $bankTxn->refresh();
            $invoiceTxn->refresh();

            expect($count)->toBe(1)
                ->and($match->status)->toBe(MatchStatus::Rejected)
                ->and($bankTxn->reconciliation_status)->toBe(ReconciliationStatus::Unreconciled)
                ->and($invoiceTxn->reconciliation_status)->toBe(ReconciliationStatus::Unreconciled);
        });

        it('rejects suggested matches without touching transaction status', function () {
            $bankTxn = Transaction::factory()->debit(5000)->create([
                'imported_file_id' => $this->bankFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);
            $invoiceTxn = Transaction::factory()->debit(5000)->create([
                'imported_file_id' => $this->invoiceFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $match = ReconciliationMatch::factory()->suggested()->create([
                'bank_transaction_id' => $bankTxn->id,
                'invoice_transaction_id' => $invoiceTxn->id,
            ]);

            $count = $this->service->rejectAllSuggestions($bankTxn);

            $match->refresh();
            $bankTxn->refresh();

            expect($count)->toBe(1)
                ->and($match->status)->toBe(MatchStatus::Rejected)
                ->and($bankTxn->reconciliation_status)->toBe(ReconciliationStatus::Unreconciled);
        });

        it('returns zero and changes nothing when there are no active matches', function () {
            $bankTxn = Transaction::factory()->debit(5000)->create([
                'imported_file_id' => $this->bankFile->id,
                'reconciliation_status' => ReconciliationStatus::Unreconciled,
            ]);

            $count = $this->service->rejectAllSuggestions($bankTxn);

            expect($count)->toBe(0)
                ->and($bankTxn->fresh()->reconciliation_status)->toBe(ReconciliationStatus::Unreconciled);
        });
    });
});
