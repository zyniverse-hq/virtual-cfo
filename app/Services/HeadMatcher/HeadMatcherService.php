<?php

namespace App\Services\HeadMatcher;

use App\Ai\Agents\HeadMatcher;
use App\Enums\MappingType;
use App\Models\AccountHead;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Services\DataPrivacy\Pseudonymizer;
use App\Services\RecurringPatterns\RecurringPatternService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class HeadMatcherService
{
    protected float $confidenceThreshold = 0.8;

    protected int $ruleChunkSize = 500;

    protected int $aiChunkSize = 20;

    public function __construct(
        protected RuleBasedMatcher $ruleBasedMatcher,
        protected RecurringPatternService $recurringPatternService = new RecurringPatternService,
        protected Pseudonymizer $pseudonymizer = new Pseudonymizer,
    ) {}

    public function setConfidenceThreshold(float $threshold): static
    {
        $this->confidenceThreshold = $threshold;

        return $this;
    }

    /**
     * Run the full matching pipeline: rules first, then recurring patterns, then AI.
     */
    public function matchForFile(ImportedFile $importedFile): array
    {
        $importedFile->loadMissing('bankAccount');

        $hasUnmapped = $importedFile->transactions()
            ->where('mapping_type', MappingType::Unmapped)
            ->exists();

        if (! $hasUnmapped) {
            return ['rule_matched' => 0, 'recurring_matched' => 0, 'ai_matched' => 0, 'unmatched' => 0];
        }

        $companyId = $importedFile->company_id;

        // Pass 1: Rule-based matching in chunks
        $ruleCount = $this->runChunkedRuleMatching($importedFile, $companyId);

        // Pass 2: Recurring pattern matching for remaining unmapped
        $recurringCount = $this->runRecurringPatternMatching($importedFile);

        // Pass 3: AI matching in chunks for remaining unmapped
        $aiCount = $this->runChunkedAiMatching($importedFile, $companyId);

        // Update file stats
        $importedFile->update([
            'mapped_rows' => $importedFile->transactions()
                ->where('mapping_type', '!=', MappingType::Unmapped)
                ->count(),
        ]);

        return [
            'rule_matched' => $ruleCount,
            'recurring_matched' => $recurringCount,
            'ai_matched' => $aiCount,
            'unmatched' => $importedFile->transactions()
                ->where('mapping_type', MappingType::Unmapped)
                ->count(),
        ];
    }

    /**
     * Run rule-based matching in chunks to avoid loading all transactions at once.
     */
    protected function runChunkedRuleMatching(ImportedFile $importedFile, int $companyId): int
    {
        $totalMatched = 0;

        $bankName = $importedFile->bankAccount?->name ?? $importedFile->bank_name;

        $importedFile->transactions()
            ->where('mapping_type', MappingType::Unmapped)
            ->chunkById($this->ruleChunkSize, function (Collection $transactions) use ($bankName, $companyId, &$totalMatched) {
                $ruleMatches = $this->ruleBasedMatcher->match($transactions, $bankName, $companyId);
                $totalMatched += $this->ruleBasedMatcher->applyMatches($ruleMatches);
            });

        return $totalMatched;
    }

    /**
     * Run recurring pattern matching on remaining unmapped transactions.
     */
    protected function runRecurringPatternMatching(ImportedFile $importedFile): int
    {
        $totalMatched = 0;

        $importedFile->transactions()
            ->where('mapping_type', MappingType::Unmapped)
            ->chunkById($this->ruleChunkSize, function (Collection $transactions) use (&$totalMatched) {
                /** @var Transaction $transaction */
                foreach ($transactions as $transaction) {
                    $pattern = $this->recurringPatternService->matchTransaction($transaction);

                    if ($pattern && $pattern->account_head_id !== null) {
                        $totalMatched++;
                    }
                }
            });

        return $totalMatched;
    }

    /**
     * Run AI matching in batches of descriptions per agent call.
     */
    protected function runChunkedAiMatching(ImportedFile $importedFile, int $companyId): int
    {
        $totalMatched = 0;

        // Load chart of accounts once for all chunks
        $chartOfAccounts = $this->loadChartOfAccounts($companyId);

        $importedFile->transactions()
            ->where('mapping_type', MappingType::Unmapped)
            ->chunkById($this->aiChunkSize, function (Collection $transactions) use (&$totalMatched, $chartOfAccounts, $companyId) {
                try {
                    $totalMatched += $this->runAiMatching($transactions, $chartOfAccounts, $companyId);
                } catch (\Throwable $e) {
                    Log::warning('AI matching chunk failed, skipping', [
                        'transaction_ids' => $transactions->pluck('id')->all(),
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        return $totalMatched;
    }

    /**
     * Load active account heads formatted for the AI agent.
     */
    protected function loadChartOfAccounts(int $companyId): string
    {
        return AccountHead::where('is_active', true)
            ->where('company_id', $companyId)
            ->get()
            ->map(fn (AccountHead $head) => "{$head->id}: {$head->name} ({$head->group_name})")
            ->implode("\n");
    }

    /**
     * Run AI matching on a collection of unmapped transactions.
     */
    protected function runAiMatching(Collection $transactions, string $chartOfAccounts, int $companyId): int
    {
        $descriptions = $transactions->map(fn (Transaction $t) => [
            'id' => $t->id,
            'description' => $t->description,
            'debit' => $t->debit,
            'credit' => $t->credit,
        ]);

        $prompt = "Match these transactions to account heads:\n\n";
        foreach ($descriptions as $desc) {
            $amount = $desc['debit'] ? "Debit: {$desc['debit']}" : "Credit: {$desc['credit']}";
            $prompt .= "ID {$desc['id']}: {$desc['description']} ({$amount})\n";
        }

        $maskedPrompt = $this->pseudonymizer->mask($prompt);
        $this->pseudonymizer->reset();

        $agent = HeadMatcher::make()->withChartOfAccounts($chartOfAccounts);
        $response = $agent->prompt($maskedPrompt);

        $matched = 0;

        foreach ($response['matches'] ?? [] as $match) {
            $head = $this->resolveAccountHead($match, $companyId);

            if (! $head) {
                continue;
            }

            Transaction::where('id', $match['transaction_id'])
                ->where('mapping_type', MappingType::Unmapped)
                ->update([
                    'account_head_id' => $head->id,
                    'mapping_type' => MappingType::Ai,
                    'ai_confidence' => $match['confidence'],
                ]);
            $matched++;
        }

        return $matched;
    }

    /**
     * Resolve an account head from AI match data, preferring ID lookup with name fallback.
     *
     * @param  array<string, mixed>  $match
     */
    private function resolveAccountHead(array $match, int $companyId): ?AccountHead
    {
        // Primary: lookup by ID scoped to this company
        if (isset($match['suggested_head_id'])) {
            $head = AccountHead::where('id', $match['suggested_head_id'])
                ->where('company_id', $companyId)
                ->first();

            if ($head) {
                return $head;
            }
        }

        // Fallback: lookup by name scoped to this company
        if (isset($match['suggested_head_name'])) {
            $normalizedName = (string) preg_replace('/\s+/', ' ', trim($match['suggested_head_name']));
            $head = AccountHead::where('name', $normalizedName)
                ->where('company_id', $companyId)
                ->first();

            if ($head) {
                Log::warning('AI matching: account head resolved by name fallback', [
                    'suggested_head_id' => $match['suggested_head_id'] ?? null,
                    'suggested_head_name' => $match['suggested_head_name'],
                    'resolved_id' => $head->id,
                ]);

                return $head;
            }
        }

        Log::warning('AI matching: could not resolve account head', [
            'suggested_head_id' => $match['suggested_head_id'] ?? null,
            'suggested_head_name' => $match['suggested_head_name'] ?? null,
        ]);

        return null;
    }
}
