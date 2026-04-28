<?php

namespace App\Services\Reconciliation;

use App\Enums\MatchMethod;
use App\Enums\MatchStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\StatementType;
use App\Models\ImportedFile;
use App\Models\ReconciliationMatch;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @phpstan-type Candidate array{invoice: Transaction, confidence: float, method: MatchMethod}
 */
class ReconciliationService
{
    /**
     * Default tolerance for amount matching (in currency units).
     * Allows for minor rounding differences.
     */
    private const DEFAULT_AMOUNT_TOLERANCE = 1.0;

    /**
     * Default date window in days for date proximity matching.
     * Payment is expected within this many days after invoice date.
     */
    private const DEFAULT_DATE_WINDOW = 60;

    /**
     * Minimum string similarity percentage (0-100) to consider a party name match.
     */
    private const MIN_PARTY_SIMILARITY = 60;

    /**
     * Run the full reconciliation process for a bank file against an invoice file.
     */
    public function reconcile(
        ImportedFile $bankFile,
        ImportedFile $invoiceFile,
        float $tolerance = self::DEFAULT_AMOUNT_TOLERANCE,
        int $dayWindow = self::DEFAULT_DATE_WINDOW,
    ): ReconciliationResult {
        $result = new ReconciliationResult;

        $bankTransactions = $bankFile->transactions()
            ->where('reconciliation_status', ReconciliationStatus::Unreconciled)
            ->get();

        $invoiceTransactions = $invoiceFile->transactions()
            ->where('reconciliation_status', ReconciliationStatus::Unreconciled)
            ->get();

        if ($bankTransactions->isEmpty() || $invoiceTransactions->isEmpty()) {
            $this->flagUnmatched($bankFile, $invoiceFile);
            $result->flagged = $bankTransactions->count() + $invoiceTransactions->count();

            return $result;
        }

        // Track which invoices have been matched to avoid double-matching
        /** @var Collection<int, int> $matchedInvoiceIds */
        $matchedInvoiceIds = collect();

        DB::transaction(function () use (
            $bankTransactions,
            $invoiceTransactions,
            &$matchedInvoiceIds,
            &$result,
            $tolerance,
            $dayWindow,
        ) {
            foreach ($bankTransactions as $bankTxn) {
                /** @var Transaction $bankTxn */
                $availableInvoices = $invoiceTransactions->reject(
                    fn (Transaction $inv) => $matchedInvoiceIds->contains($inv->id)
                );

                if ($availableInvoices->isEmpty()) {
                    break;
                }

                $match = $this->matchByAmount($bankTxn, $availableInvoices, $tolerance);

                if (! $match) {
                    $match = $this->matchByAmountAndDate($bankTxn, $availableInvoices, $tolerance, $dayWindow);
                }

                if (! $match) {
                    $match = $this->matchByPartyName($bankTxn, $availableInvoices, $tolerance, $dayWindow);
                }

                if ($match) {
                    $matchedInvoiceIds->push($match->invoice_transaction_id);
                    $result->matched++;
                }
            }
        });

        // Flag remaining unmatched items
        $this->flagUnmatched($bankFile, $invoiceFile);

        // Count flagged items
        $result->flagged = Transaction::where(function ($query) use ($bankFile, $invoiceFile) {
            $query->where('imported_file_id', $bankFile->id)
                ->orWhere('imported_file_id', $invoiceFile->id);
        })->where('reconciliation_status', ReconciliationStatus::Flagged)->count();

        $result->unreconciled = Transaction::where(function ($query) use ($bankFile, $invoiceFile) {
            $query->where('imported_file_id', $bankFile->id)
                ->orWhere('imported_file_id', $invoiceFile->id);
        })->where('reconciliation_status', ReconciliationStatus::Unreconciled)->count();

        return $result;
    }

    /**
     * Match by exact amount (within tolerance).
     * Returns the created ReconciliationMatch or null if no match found.
     *
     * @param  Collection<int, Transaction>  $invoices
     */
    public function matchByAmount(
        Transaction $bankTxn,
        Collection $invoices,
        float $tolerance = self::DEFAULT_AMOUNT_TOLERANCE,
    ): ?ReconciliationMatch {
        $bankAmount = $bankTxn->amount;

        if ($bankAmount === null) {
            return null;
        }

        foreach ($invoices as $invoice) {
            /** @var Transaction $invoice */
            $invoiceAmount = $invoice->amount;

            if ($invoiceAmount === null) {
                continue;
            }

            if (abs($bankAmount - $invoiceAmount) <= $tolerance) {
                return $this->createMatch(
                    $bankTxn,
                    $invoice,
                    $this->calculateAmountConfidence($bankAmount, $invoiceAmount),
                    MatchMethod::Amount,
                );
            }
        }

        return null;
    }

    /**
     * Match by amount + date proximity.
     * The bank payment date should be on or after the invoice date, within the day window.
     *
     * @param  Collection<int, Transaction>  $invoices
     */
    public function matchByAmountAndDate(
        Transaction $bankTxn,
        Collection $invoices,
        float $tolerance = self::DEFAULT_AMOUNT_TOLERANCE,
        int $dayWindow = self::DEFAULT_DATE_WINDOW,
    ): ?ReconciliationMatch {
        $bankAmount = $bankTxn->amount;

        /** @var Carbon|null $bankDate */
        $bankDate = $bankTxn->date;

        if ($bankAmount === null || $bankDate === null) {
            return null;
        }

        /** @var Collection<int, array{invoice: Transaction, days_diff: int}> $candidates */
        $candidates = collect();

        foreach ($invoices as $invoice) {
            /** @var Transaction $invoice */
            $invoiceAmount = $invoice->amount;

            /** @var Carbon|null $invoiceDate */
            $invoiceDate = $invoice->date;

            if ($invoiceAmount === null || $invoiceDate === null) {
                continue;
            }

            if (abs($bankAmount - $invoiceAmount) > $tolerance) {
                continue;
            }

            // Bank payment should be on or after invoice date, within window
            if ($bankDate->lt($invoiceDate)) {
                continue;
            }

            $daysDiff = (int) abs($bankDate->diffInDays($invoiceDate));

            if ($daysDiff <= $dayWindow) {
                $candidates->push([
                    'invoice' => $invoice,
                    'days_diff' => $daysDiff,
                ]);
            }
        }

        if ($candidates->isEmpty()) {
            return null;
        }

        // Pick the closest date match
        $best = $candidates->sortBy('days_diff')->first();

        /** @var Transaction $bestInvoice */
        $bestInvoice = $best['invoice'];

        $amountConfidence = $this->calculateAmountConfidence($bankAmount, $bestInvoice->amount ?? 0.0);
        $dateConfidence = max(0.0, 1.0 - ($best['days_diff'] / $dayWindow));
        $confidence = ($amountConfidence + $dateConfidence) / 2;

        return $this->createMatch(
            $bankTxn,
            $bestInvoice,
            $confidence,
            MatchMethod::AmountDate,
        );
    }

    /**
     * Match by amount + date + party name fuzzy match.
     * Uses PHP's similar_text() for fuzzy string comparison.
     *
     * @param  Collection<int, Transaction>  $invoices
     */
    public function matchByPartyName(
        Transaction $bankTxn,
        Collection $invoices,
        float $tolerance = self::DEFAULT_AMOUNT_TOLERANCE,
        int $dayWindow = self::DEFAULT_DATE_WINDOW,
    ): ?ReconciliationMatch {
        $bankAmount = $bankTxn->amount;

        /** @var Carbon|null $bankDate */
        $bankDate = $bankTxn->date;

        /** @var string|null $bankDescription */
        $bankDescription = $bankTxn->description;

        if ($bankAmount === null || $bankDescription === null) {
            return null;
        }

        $bestMatch = null;
        $bestConfidence = 0.0;

        foreach ($invoices as $invoice) {
            /** @var Transaction $invoice */
            $invoiceAmount = $invoice->amount;

            if ($invoiceAmount === null) {
                continue;
            }

            if (abs($bankAmount - $invoiceAmount) > $tolerance) {
                continue;
            }

            // Date check (optional - if dates available)
            if ($bankDate !== null && $invoice->date !== null) {
                /** @var Carbon $invDate */
                $invDate = $invoice->date;
                if ($bankDate->lt($invDate)) {
                    continue;
                }
                $daysDiff = (int) abs($bankDate->diffInDays($invDate));
                if ($daysDiff > $dayWindow) {
                    continue;
                }
            }

            $similarity = $this->bestPartyNameSimilarity($bankDescription, $invoice);

            if ($similarity >= self::MIN_PARTY_SIMILARITY && $similarity > $bestConfidence) {
                $bestMatch = $invoice;
                $bestConfidence = $similarity;
            }
        }

        if ($bestMatch === null) {
            return null;
        }

        $amountConfidence = $this->calculateAmountConfidence($bankAmount, $bestMatch->amount ?? 0.0);
        $nameConfidence = $bestConfidence / 100;
        $confidence = ($amountConfidence + $nameConfidence) / 2;

        return $this->createMatch(
            $bankTxn,
            $bestMatch,
            round($confidence, 4),
            MatchMethod::AmountDateParty,
        );
    }

    /**
     * Flag all unmatched transactions in both files.
     */
    public function flagUnmatched(ImportedFile $bankFile, ImportedFile $invoiceFile): void
    {
        // Flag unreconciled bank transactions
        Transaction::where('imported_file_id', $bankFile->id)
            ->where('reconciliation_status', ReconciliationStatus::Unreconciled)
            ->update(['reconciliation_status' => ReconciliationStatus::Flagged]);

        // Flag unreconciled invoice transactions
        Transaction::where('imported_file_id', $invoiceFile->id)
            ->where('reconciliation_status', ReconciliationStatus::Unreconciled)
            ->update(['reconciliation_status' => ReconciliationStatus::Flagged]);
    }

    /**
     * Enrich matched bank transactions with invoice data from their raw_data.
     */
    public function enrichMatchedTransactions(ImportedFile $bankFile): void
    {
        $matches = ReconciliationMatch::whereHas(
            'bankTransaction',
            fn ($q) => $q->where('imported_file_id', $bankFile->id)
                ->where('reconciliation_status', ReconciliationStatus::Matched)
        )
            ->confirmed()
            ->with(['bankTransaction', 'invoiceTransaction'])
            ->get();

        foreach ($matches as $match) {
            $this->enrichSingleMatch($match);
        }
    }

    /**
     * Find candidate invoice matches for a bank transaction without creating records.
     *
     * @param  Collection<int, Transaction>  $invoices
     * @return array<int, array{invoice: Transaction, confidence: float, method: MatchMethod}>
     */
    public function findCandidates(
        Transaction $bankTxn,
        Collection $invoices,
        float $tolerance = self::DEFAULT_AMOUNT_TOLERANCE,
        int $dayWindow = self::DEFAULT_DATE_WINDOW,
    ): array {
        $bankAmount = $bankTxn->amount;

        if ($bankAmount === null) {
            return [];
        }

        $candidates = [];

        foreach ($invoices as $invoice) {
            /** @var Transaction $invoice */
            $invoiceAmount = $invoice->amount;

            if ($invoiceAmount === null) {
                continue;
            }

            if (abs($bankAmount - $invoiceAmount) > $tolerance) {
                continue;
            }

            $amountConfidence = $this->calculateAmountConfidence($bankAmount, $invoiceAmount);

            // Try date-based scoring
            /** @var Carbon|null $bankDate */
            $bankDate = $bankTxn->date;
            /** @var Carbon|null $invoiceDate */
            $invoiceDate = $invoice->date;

            $dateConfidence = 0.0;
            $method = MatchMethod::Amount;

            if ($bankDate !== null && $invoiceDate !== null && ! $bankDate->lt($invoiceDate)) {
                $daysDiff = (int) abs($bankDate->diffInDays($invoiceDate));
                if ($daysDiff <= $dayWindow) {
                    $dateConfidence = max(0.0, 1.0 - ($daysDiff / $dayWindow));
                    $method = MatchMethod::AmountDate;
                }
            }

            // Try party name scoring
            /** @var string|null $bankDescription */
            $bankDescription = $bankTxn->description;
            $nameConfidence = 0.0;

            if ($bankDescription !== null) {
                $similarity = $this->bestPartyNameSimilarity($bankDescription, $invoice);

                if ($similarity >= self::MIN_PARTY_SIMILARITY) {
                    $nameConfidence = $similarity / 100;
                    $method = MatchMethod::AmountDateParty;
                }
            }

            // Weighted combination: amount is primary (60%), date and party are corroborative (20% each)
            $confidence = ($amountConfidence * 0.6) + ($dateConfidence * 0.2) + ($nameConfidence * 0.2);

            $candidates[] = [
                'invoice' => $invoice,
                'confidence' => round($confidence, 4),
                'method' => $method,
            ];
        }

        // Sort by confidence descending
        usort($candidates, fn (array $a, array $b) => $b['confidence'] <=> $a['confidence']);

        return $candidates;
    }

    /**
     * Scan for invoice match candidates and create suggested matches.
     * Returns the number of suggestions created.
     */
    public function suggestMatches(ImportedFile $invoiceFile): int
    {
        $companyId = $invoiceFile->company_id;

        // Get all unreconciled invoice transactions from this file
        /** @var Collection<int, Transaction> $invoiceTransactions */
        $invoiceTransactions = $invoiceFile->transactions()
            ->where('reconciliation_status', ReconciliationStatus::Unreconciled)
            ->get();

        if ($invoiceTransactions->isEmpty()) {
            return 0;
        }

        // Get all unreconciled bank/CC transactions for this company
        $bankTransactions = Transaction::query()
            ->where('company_id', $companyId)
            ->where('reconciliation_status', ReconciliationStatus::Unreconciled)
            ->whereHas('importedFile', fn ($q) => $q->whereIn('statement_type', [
                StatementType::Bank,
                StatementType::CreditCard,
            ]))
            ->get();

        if ($bankTransactions->isEmpty()) {
            return 0;
        }

        // Pre-load IDs that already have suggestions (1 query instead of N)
        $alreadySuggestedIds = ReconciliationMatch::whereIn('bank_transaction_id', $bankTransactions->pluck('id'))
            ->suggested()
            ->pluck('bank_transaction_id')
            ->flip();

        $suggestionsCreated = 0;

        foreach ($bankTransactions as $bankTxn) {
            if ($alreadySuggestedIds->has($bankTxn->id)) {
                continue;
            }

            $candidates = $this->findCandidates($bankTxn, $invoiceTransactions);

            if (empty($candidates)) {
                continue;
            }

            // Create suggestion for the best candidate
            $best = $candidates[0];
            $this->createMatch(
                $bankTxn,
                $best['invoice'],
                $best['confidence'],
                $best['method'],
                MatchStatus::Suggested,
            );
            $suggestionsCreated++;
        }

        return $suggestionsCreated;
    }

    /**
     * Confirm a suggested match: update status, set transaction statuses, reject competing suggestions.
     */
    public function confirmSuggestion(ReconciliationMatch $match): void
    {
        if ($match->status !== MatchStatus::Suggested) {
            throw new \InvalidArgumentException(
                "Cannot confirm match #{$match->id}: status is {$match->status->value}, expected suggested"
            );
        }

        $match->loadMissing(['bankTransaction', 'invoiceTransaction']);

        DB::transaction(function () use ($match) {
            $match->update(['status' => MatchStatus::Confirmed]);

            $match->bankTransaction->update(['reconciliation_status' => ReconciliationStatus::Matched]);
            $match->invoiceTransaction->update(['reconciliation_status' => ReconciliationStatus::Matched]);

            // Reject competing suggestions for the same bank or invoice transaction
            ReconciliationMatch::where('id', '!=', $match->id)
                ->suggested()
                ->where(fn ($q) => $q
                    ->where('bank_transaction_id', $match->bank_transaction_id)
                    ->orWhere('invoice_transaction_id', $match->invoice_transaction_id)
                )
                ->update(['status' => MatchStatus::Rejected]);

            $this->enrichSingleMatch($match);
        });
    }

    /**
     * Reject a suggested match with an optional reason.
     */
    public function rejectSuggestion(ReconciliationMatch $match, ?string $reason = null): void
    {
        if ($match->status !== MatchStatus::Suggested) {
            throw new \InvalidArgumentException(
                "Cannot reject match #{$match->id}: status is {$match->status->value}, expected suggested"
            );
        }

        $data = ['status' => MatchStatus::Rejected];

        if ($reason !== null) {
            $data['notes'] = $reason;
        }

        $match->update($data);
    }

    /**
     * Reject all active matches (suggested or confirmed) for a bank transaction.
     * Confirmed matches also revert both transactions to Unreconciled.
     */
    public function rejectAllSuggestions(Transaction $bankTxn): int
    {
        $activeMatches = $bankTxn->reconciliationMatchesAsBank()
            ->whereIn('status', [MatchStatus::Suggested, MatchStatus::Confirmed])
            ->with('invoiceTransaction')
            ->get();

        if ($activeMatches->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($activeMatches, $bankTxn) {
            $confirmedMatches = $activeMatches->where('status', MatchStatus::Confirmed);

            foreach ($confirmedMatches as $match) {
                $match->invoiceTransaction?->update(['reconciliation_status' => ReconciliationStatus::Unreconciled]);
            }

            if ($confirmedMatches->isNotEmpty()) {
                $bankTxn->update(['reconciliation_status' => ReconciliationStatus::Unreconciled]);
            }

            ReconciliationMatch::whereIn('id', $activeMatches->pluck('id'))
                ->update(['status' => MatchStatus::Rejected]);
        });

        return $activeMatches->count();
    }

    /**
     * Create a reconciliation match record and optionally update transaction statuses.
     */
    public function createMatch(
        Transaction $bankTxn,
        Transaction $invoiceTxn,
        float $confidence,
        MatchMethod $method,
        MatchStatus $status = MatchStatus::Confirmed,
    ): ReconciliationMatch {
        return DB::transaction(function () use ($bankTxn, $invoiceTxn, $confidence, $method, $status) {
            $match = ReconciliationMatch::create([
                'bank_transaction_id' => $bankTxn->id,
                'invoice_transaction_id' => $invoiceTxn->id,
                'confidence' => round($confidence, 4),
                'match_method' => $method,
                'status' => $status,
            ]);

            // Only update transaction statuses for confirmed matches
            if ($status === MatchStatus::Confirmed) {
                $bankTxn->update(['reconciliation_status' => ReconciliationStatus::Matched]);
                $invoiceTxn->update(['reconciliation_status' => ReconciliationStatus::Matched]);
            }

            Log::info('Reconciliation match created', [
                'bank_transaction_id' => $bankTxn->id,
                'invoice_transaction_id' => $invoiceTxn->id,
                'method' => $method->value,
                'confidence' => $confidence,
                'status' => $status->value,
            ]);

            return $match;
        });
    }

    /**
     * Calculate the best party name similarity between a bank description and an invoice.
     * Checks both the invoice description and vendor_name from raw_data.
     */
    private function bestPartyNameSimilarity(string $bankDescription, Transaction $invoice): float
    {
        /** @var string $invoiceDescription */
        $invoiceDescription = $invoice->description ?? '';
        $similarity = $this->calculateNameSimilarity($bankDescription, $invoiceDescription);

        /** @var array<string, mixed>|null $rawData */
        $rawData = $invoice->raw_data;
        if (is_array($rawData) && isset($rawData['vendor_name'])) {
            $vendorSimilarity = $this->calculateNameSimilarity(
                $bankDescription,
                (string) $rawData['vendor_name']
            );
            $similarity = max($similarity, $vendorSimilarity);
        }

        return $similarity;
    }

    /**
     * Enrich a single confirmed match's bank transaction with invoice data.
     */
    private function enrichSingleMatch(ReconciliationMatch $match): void
    {
        /** @var Transaction $invoiceTxn */
        $invoiceTxn = $match->invoiceTransaction;

        /** @var array<string, mixed>|null $invoiceRawData */
        $invoiceRawData = $invoiceTxn->raw_data;

        if (! is_array($invoiceRawData)) {
            return;
        }

        /** @var Transaction $bankTxn */
        $bankTxn = $match->bankTransaction;

        $enrichment = [
            'reconciled_invoice_id' => $invoiceTxn->id,
            'vendor_name' => $invoiceRawData['vendor_name'] ?? null,
            'vendor_gstin' => $invoiceRawData['vendor_gstin'] ?? null,
            'invoice_number' => $invoiceRawData['invoice_number'] ?? $invoiceTxn->reference_number,
            'base_amount' => $invoiceRawData['base_amount'] ?? null,
            'cgst_amount' => $invoiceRawData['cgst_amount'] ?? null,
            'sgst_amount' => $invoiceRawData['sgst_amount'] ?? null,
            'tds_amount' => $invoiceRawData['tds_amount'] ?? null,
            'line_items' => $invoiceRawData['line_items'] ?? null,
        ];

        /** @var array<string, mixed> $currentRawData */
        $currentRawData = $bankTxn->raw_data ?? [];
        $bankTxn->update([
            'raw_data' => array_merge($currentRawData, $enrichment),
        ]);
    }

    /**
     * Calculate confidence based on amount difference.
     * Exact match = 1.0, at tolerance boundary = 0.9.
     */
    private function calculateAmountConfidence(float $bankAmount, float $invoiceAmount): float
    {
        $diff = abs($bankAmount - $invoiceAmount);

        if ($diff === 0.0) {
            return 1.0;
        }

        // Confidence decreases linearly from 1.0 to 0.9 as diff approaches tolerance
        $maxAmount = max($bankAmount, $invoiceAmount, 1.0);

        return max(0.9, 1.0 - ($diff / $maxAmount));
    }

    /**
     * Calculate string similarity percentage between two party names.
     * Normalizes strings before comparison.
     */
    private function calculateNameSimilarity(string $bankDescription, string $invoiceDescription): float
    {
        $normalized1 = $this->normalizePartyName($bankDescription);
        $normalized2 = $this->normalizePartyName($invoiceDescription);

        if ($normalized1 === '' || $normalized2 === '') {
            return 0.0;
        }

        // Check if one contains the other (common with abbreviated names)
        if (str_contains($normalized1, $normalized2) || str_contains($normalized2, $normalized1)) {
            return 90.0;
        }

        $similarity = 0.0;
        similar_text($normalized1, $normalized2, $similarity);

        return $similarity;
    }

    /**
     * Normalize a party name for comparison.
     * Strips common prefixes, suffixes, and noise words from bank narrations.
     */
    private function normalizePartyName(string $name): string
    {
        $name = mb_strtolower(trim($name));

        // Remove common bank narration prefixes
        $prefixes = ['neft-', 'rtgs-', 'upi/', 'imps/', 'neft/', 'rtgs/'];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $name = mb_substr($name, mb_strlen($prefix));
            }
        }

        // Remove common suffixes
        $suffixes = [' pvt ltd', ' private limited', ' limited', ' ltd', ' llp', ' inc'];
        foreach ($suffixes as $suffix) {
            if (str_ends_with($name, $suffix)) {
                $name = mb_substr($name, 0, mb_strlen($name) - mb_strlen($suffix));
            }
        }

        // Remove reference numbers (sequences of digits)
        $name = (string) preg_replace('/\d{4,}/', '', $name);

        // Remove extra whitespace and special characters
        $name = (string) preg_replace('/[^a-z0-9\s]/', ' ', $name);
        $name = (string) preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }
}
