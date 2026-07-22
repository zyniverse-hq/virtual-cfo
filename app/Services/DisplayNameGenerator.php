<?php

namespace App\Services;

use App\Enums\StatementType;
use App\Models\ImportedFile;
use App\Models\Transaction;
use Carbon\Carbon;

class DisplayNameGenerator
{
    public function generate(ImportedFile $file): string
    {
        if ($file->statement_type === StatementType::Invoice) {
            return $this->generateInvoiceName($file);
        }

        $period = $this->resolvePeriod($file);
        $variant = $file->card_variant ?? $file->creditCard?->name;

        if ($variant) {
            return "{$file->bank_name}_{$variant}_{$period}";
        }

        return "{$file->bank_name}_{$period}";
    }

    private function generateInvoiceName(ImportedFile $file): string
    {
        $file->loadMissing('transactions');

        /** @var Transaction|null $firstTransaction */
        $firstTransaction = $file->transactions->first();

        /** @var array<string, mixed>|null $raw */
        $raw = $firstTransaction?->raw_data;

        $partyName = $this->stripCompanySuffix($raw['vendor_name'] ?? $raw['buyer_name'] ?? null)
            ?? 'Invoice';

        /** @var Carbon $txnDate */
        $txnDate = $firstTransaction?->date ?? $file->created_at;
        $month = $txnDate->format('M_Y');

        $invoiceNumber = $raw['invoice_number'] ?? null;

        $parts = array_filter([$partyName, $month, $invoiceNumber]);

        return implode('_', $parts);
    }

    private function stripCompanySuffix(?string $name): ?string
    {
        if (! $name) {
            return null;
        }

        return trim(preg_replace('/\s+(?:Private Limited|Pvt\.?\s*Ltd\.?|Limited|Ltd\.?|LLP)\s*$/i', '', $name) ?? $name);
    }

    private function resolvePeriod(ImportedFile $file): string
    {
        if (! $file->statement_period) {
            return $file->created_at->format('M_Y');
        }

        return $this->extractEndMonth($file->statement_period);
    }

    private function extractEndMonth(string $period): string
    {
        $dateToParse = str_contains($period, ' to ')
            ? trim(substr($period, strpos($period, ' to ') + 4))
            : $period;

        try {
            return Carbon::parse($dateToParse)->format('M_Y');
        } catch (\Exception) {
            return $period;
        }
    }
}
