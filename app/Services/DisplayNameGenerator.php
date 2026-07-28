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

        $bankName = $file->bank_name ?? $file->bankAccount?->name;
        $variant = $file->card_variant ?? $file->creditCard?->name;

        $parts = array_filter([$bankName, $variant, $period]);

        return implode(' ', $parts);
    }

    private function generateInvoiceName(ImportedFile $file): string
    {
        $file->loadMissing('transactions');

        /** @var Transaction|null $firstTransaction */
        $firstTransaction = $file->transactions->first();

        /** @var array<string, mixed>|null $raw */
        $raw = $firstTransaction?->raw_data;

        $invoiceNumber = $raw['invoice_number'] ?? null;
        $buyerName = $this->stripCompanySuffix($raw['vendor_name'] ?? $raw['buyer_name'] ?? null);
        $description = $this->shortenDescription($raw['line_items'][0]['description'] ?? null);

        $parts = array_filter([$invoiceNumber, $buyerName, $description]);

        if (empty($parts)) {
            return $file->bank_name ?? 'Invoice';
        }

        return implode(' ', $parts);
    }

    private function stripCompanySuffix(?string $name): ?string
    {
        if (! $name) {
            return null;
        }

        return trim(preg_replace('/\s+(?:Private Limited|Pvt\.?\s*Ltd\.?|Limited|Ltd\.?|LLP)\s*$/i', '', $name) ?? $name);
    }

    private function shortenDescription(?string $description): ?string
    {
        if (! $description) {
            return null;
        }

        $primary = explode(' - ', $description)[0];
        $words = array_values(array_filter(explode(' ', trim($primary))));

        return implode(' ', array_slice($words, 0, 2));
    }

    private function resolvePeriod(ImportedFile $file): string
    {
        if (! $file->statement_period) {
            return $file->created_at->format('M Y');
        }

        return $this->extractEndMonth($file->statement_period);
    }

    private function extractEndMonth(string $period): string
    {
        $dateToParse = str_contains($period, ' to ')
            ? trim(substr($period, strpos($period, ' to ') + 4))
            : $period;

        try {
            return Carbon::parse($dateToParse)->format('M Y');
        } catch (\Exception) {
            return $period;
        }
    }
}
