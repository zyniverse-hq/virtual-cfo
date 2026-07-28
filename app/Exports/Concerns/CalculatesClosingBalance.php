<?php

namespace App\Exports\Concerns;

use App\Enums\StatementType;
use App\Models\ImportedFile;
use App\Models\Transaction;
use Illuminate\Support\Collection;

trait CalculatesClosingBalance
{
    /**
     * Build the spreadsheet closing-balance formula for the given statement type.
     */
    protected function closingBalanceFormula(
        ?StatementType $statementType,
        string $openingCell,
        string $debitCell,
        string $creditCell,
    ): string {
        if ($statementType?->closingBalanceAddsDebit()) {
            return "={$openingCell}+{$debitCell}-{$creditCell}";
        }

        return "={$openingCell}+{$creditCell}-{$debitCell}";
    }

    /**
     * Compute the literal closing-balance value for the given statement type.
     */
    protected function closingBalanceValue(
        ?StatementType $statementType,
        float $openingBalance,
        float $totalDebit,
        float $totalCredit,
    ): float {
        if ($statementType?->closingBalanceAddsDebit()) {
            return $openingBalance + $totalDebit - $totalCredit;
        }

        return $openingBalance + $totalCredit - $totalDebit;
    }

    /**
     * Compute a statement file's closing balance from its transactions.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    protected function closingBalanceValueForFile(ImportedFile $file, Collection $transactions): float
    {
        $totalDebit = (float) $transactions->sum(fn (Transaction $transaction): float => (float) $transaction->debit);
        $totalCredit = (float) $transactions->sum(fn (Transaction $transaction): float => (float) $transaction->credit);
        $openingBalance = $file->opening_balance !== null ? (float) $file->opening_balance : 0.0;

        return $this->closingBalanceValue($file->statement_type, $openingBalance, $totalDebit, $totalCredit);
    }
}
