<?php

namespace App\Services\HeadMatcher;

use App\Enums\MappingType;
use App\Enums\MatchType;
use App\Models\HeadMapping;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RuleBasedMatcher
{
    /**
     * Match type specificity scores — higher means more specific.
     *
     * @var array<string, int>
     */
    private const MATCH_TYPE_SPECIFICITY = [
        'exact' => 3,
        'regex' => 2,
        'contains' => 1,
    ];

    /**
     * Match transactions against existing head mapping rules.
     *
     * Rules are ordered by specificity before matching:
     * 1. Manual priority (lower number = higher priority, null = no override)
     * 2. Match type specificity: exact > regex > contains
     * 3. Bank name specificity: bank-specific > any-bank (null)
     * 4. Usage count descending (most-used rules first)
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, array{transaction_id: int, account_head_id: int, mapping_id: int}>
     */
    public function match(Collection $transactions, ?string $bankName = null, ?int $companyId = null): array
    {
        $query = HeadMapping::with('accountHead')
            ->whereHas('accountHead', fn (Builder $q) => $q->where('is_active', true));

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        if ($bankName) {
            $query->where(function (Builder $q) use ($bankName) {
                $q->whereNull('bank_name')
                    ->orWhere('bank_name', $bankName);
            });
        }

        $rules = $this->sortByPriority($query->get());

        $matches = [];
        /** @var array<int, int> $usageCounts */
        $usageCounts = [];

        foreach ($transactions as $transaction) {
            if ($transaction->mapping_type !== MappingType::Unmapped) {
                continue;
            }

            $description = $transaction->description;

            foreach ($rules as $rule) {
                if ($rule->matches($description)) {
                    $matches[] = [
                        'transaction_id' => $transaction->id,
                        'account_head_id' => $rule->account_head_id,
                        'mapping_id' => $rule->id,
                    ];

                    $usageCounts[$rule->id] = ($usageCounts[$rule->id] ?? 0) + 1;

                    break; // First match wins (from priority-ordered rules)
                }
            }
        }

        // Bulk update usage counts — one query per matched rule instead of per match
        foreach ($usageCounts as $mappingId => $count) {
            HeadMapping::where('id', $mappingId)->increment('usage_count', $count);
        }

        return $matches;
    }

    /**
     * Sort rules by priority for deterministic matching.
     *
     * @param  Collection<int, HeadMapping>  $rules
     * @return Collection<int, HeadMapping>
     */
    private function sortByPriority(Collection $rules): Collection
    {
        return $rules->sort(function (HeadMapping $a, HeadMapping $b): int {
            // 1. Manual priority takes precedence (lower number = higher priority)
            // Rules with a manual priority always come before rules without one
            if ($a->priority !== null && $b->priority === null) {
                return -1;
            }
            if ($a->priority === null && $b->priority !== null) {
                return 1;
            }
            if ($a->priority !== null && $b->priority !== null) {
                $priorityDiff = $a->priority - $b->priority;
                if ($priorityDiff !== 0) {
                    return $priorityDiff;
                }
            }

            // 2. Match type specificity: exact > regex > contains
            $aSpecificity = $this->matchTypeSpecificity($a->match_type);
            $bSpecificity = $this->matchTypeSpecificity($b->match_type);
            $specificityDiff = $bSpecificity - $aSpecificity; // Higher specificity first
            if ($specificityDiff !== 0) {
                return $specificityDiff;
            }

            // 3. Bank name specificity: bank-specific > null/any-bank
            $aBankSpecific = $a->bank_name !== null ? 1 : 0;
            $bBankSpecific = $b->bank_name !== null ? 1 : 0;
            $bankDiff = $bBankSpecific - $aBankSpecific; // Bank-specific first
            if ($bankDiff !== 0) {
                return $bankDiff;
            }

            // 4. Usage count descending
            return $b->usage_count - $a->usage_count;
        })->values();
    }

    /**
     * Get the specificity score for a match type.
     *
     * @param  MatchType|string  $matchType
     */
    private function matchTypeSpecificity(mixed $matchType): int
    {
        $value = $matchType instanceof MatchType ? $matchType->value : (string) $matchType;

        return self::MATCH_TYPE_SPECIFICITY[$value] ?? 0;
    }

    /**
     * Apply rule-based matches to transactions.
     */
    public function applyMatches(array $matches): int
    {
        $count = 0;

        foreach ($matches as $match) {
            Transaction::where('id', $match['transaction_id'])
                ->where('mapping_type', MappingType::Unmapped)
                ->update([
                    'account_head_id' => $match['account_head_id'],
                    'mapping_type' => MappingType::Auto,
                ]);

            $count++;
        }

        return $count;
    }
}
