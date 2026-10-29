<?php

namespace App\Services\DocumentProcessor;

/**
 * Normalizes statement amounts that may carry thousands separators, currency text,
 * +/- signs, brackets or Cr/Dr markers into plain positive debit/credit values.
 *
 * @phpstan-type ParsedAmount array{amount: string, sign: '+'|'-'|null, marker: 'debit'|'credit'|null}
 */
class AmountParser
{
    public const string DEBIT = 'debit';

    public const string CREDIT = 'credit';

    private const string CURRENCY = '(?:(?:₹|Rs\.?|INR|\$)\s*)?';

    private const string NUMBER = '(?<!\d)\d{1,3}(?:[ ,]\d{3})+(?!\d)(?:\.\d+)?|\d[\d,]*(?:\.\d+)?|\.\d+';

    /**
     * Parse a raw amount into its absolute value, its sign ("-" also covers brackets)
     * and the side named by a Cr/Dr marker. Returns null for empty or placeholder values.
     *
     * A sign counts only when attached to the number (optionally across a currency symbol).
     * A marker counts only as CR/DR next to the number, or a lone C/D closing the text.
     *
     * @return ParsedAmount|null
     */
    public function parse(mixed $value): ?array
    {
        if (is_int($value) || is_float($value)) {
            return [
                'amount' => $this->formatNumber(abs($value)),
                'sign' => $value < 0 ? '-' : null,
                'marker' => null,
            ];
        }

        if (! is_string($value) || ! preg_match('/'.self::NUMBER.'/', $value, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        [$number, $offset] = $match[0];
        $before = substr($value, 0, $offset);
        $after = substr($value, $offset + strlen($number));

        return [
            'amount' => str_replace([',', ' '], '', $number),
            'sign' => $this->sign($before, $after),
            'marker' => $this->marker($before, $after),
        ];
    }

    /**
     * Resolve the debit and credit amounts of a transaction.
     *
     * A value in the debit or credit column stays on that side. Only an explicit Cr/Dr
     * marker, on the value itself or on the single amount for the same figure, moves it.
     * A sign never moves a column value, so a PDF row and a CSV row with the same data
     * land on the same side.
     *
     * When both columns are empty, the single amount is split by the indicator column,
     * then by its own marker or sign, defaulting to debit. A "+" means credit. A "-" means
     * $negativeSide: money out (debit) on a bank account, but a payment or refund that
     * reduces the amount owed (credit) on a card.
     *
     * @param  'debit'|'credit'  $negativeSide
     * @return array{debit: ?string, credit: ?string}
     */
    public function resolve(
        mixed $debit,
        mixed $credit,
        mixed $amount = null,
        mixed $indicator = null,
        string $negativeSide = self::DEBIT,
    ): array {
        $single = $this->parseNonZero($amount);
        $columns = array_filter(array_map($this->parseNonZero(...), [self::DEBIT => $debit, self::CREDIT => $credit]));

        if (count($columns) === 2) {
            $columns = $this->dropPlaceholderColumn($columns, $single);
        }

        if (count($columns) === 2) {
            return [self::DEBIT => $columns[self::DEBIT]['amount'], self::CREDIT => $columns[self::CREDIT]['amount']];
        }

        $resolved = [self::DEBIT => null, self::CREDIT => null];

        if (count($columns) === 1) {
            $column = array_key_first($columns);
            $value = $columns[$column]['amount'];
            $sameAmount = $single !== null && (float) $single['amount'] === (float) $value;

            $resolved[$columns[$column]['marker'] ?? ($sameAmount ? $single['marker'] : null) ?? $column] = $value;

            return $resolved;
        }

        if ($single !== null) {
            $resolved[$this->indicatorSide($indicator) ?? $this->side($single, $negativeSide) ?? self::DEBIT] = $single['amount'];
        }

        return $resolved;
    }

    /**
     * Parse a running balance, keeping it negative when written with a minus sign or brackets.
     *
     * Without a sign, the marker for $negativeSide makes it negative and the other marker
     * positive: Dr is an overdraft on a bank account, but Cr is a credit balance on a card,
     * where a positive balance is the amount owed.
     *
     * @param  'debit'|'credit'  $negativeSide
     */
    public function parseBalance(mixed $value, string $negativeSide = self::DEBIT): ?string
    {
        $parsed = $this->parse($value);

        if ($parsed === null) {
            return null;
        }

        $isNegative = $parsed['sign'] === '-' || ($parsed['sign'] === null && $parsed['marker'] === $negativeSide);

        return $isNegative ? "-{$parsed['amount']}" : $parsed['amount'];
    }

    /**
     * Format a number without scientific notation.
     */
    private function formatNumber(int|float $number): string
    {
        if (is_int($number)) {
            return (string) $number;
        }

        return rtrim(rtrim(number_format($number, 10, '.', ''), '0'), '.');
    }

    /**
     * @return '+'|'-'|null
     */
    private function sign(string $before, string $after): ?string
    {
        $currency = self::CURRENCY;

        if (
            preg_match("/(?<![A-Za-z0-9])-\\s*{$currency}$/i", $before)
            || str_starts_with($after, '-')
            || (preg_match("/\\(\\s*{$currency}$/i", $before) && preg_match('/^\s*\)/', $after))
        ) {
            return '-';
        }

        return preg_match("/(?<![A-Za-z0-9])\\+\\s*{$currency}$/i", $before) ? '+' : null;
    }

    /**
     * With both columns filled, one is a placeholder such as "-1": keep the column that
     * matches the single amount, otherwise the one that is not negative.
     *
     * @param  array<string, ParsedAmount>  $columns
     * @param  ParsedAmount|null  $single
     * @return array<string, ParsedAmount>
     */
    private function dropPlaceholderColumn(array $columns, ?array $single): array
    {
        $matching = $single !== null
            ? array_filter($columns, fn (array $parsed): bool => (float) $parsed['amount'] === (float) $single['amount'])
            : [];

        if (count($matching) === 1) {
            return $matching;
        }

        $notNegative = array_filter($columns, fn (array $parsed): bool => $parsed['sign'] !== '-');

        return count($notNegative) === 1 ? $notNegative : $columns;
    }

    /**
     * @return ParsedAmount|null
     */
    private function parseNonZero(mixed $value): ?array
    {
        $parsed = $this->parse($value);

        return $parsed !== null && (float) $parsed['amount'] > 0 ? $parsed : null;
    }

    /**
     * The side a parsed amount names on its own: its Cr/Dr marker first, then its sign.
     *
     * @param  ParsedAmount  $parsed
     * @param  'debit'|'credit'  $negativeSide
     * @return 'debit'|'credit'|null
     */
    private function side(array $parsed, string $negativeSide): ?string
    {
        return $parsed['marker'] ?? match ($parsed['sign']) {
            '-' => $negativeSide,
            '+' => self::CREDIT,
            null => null,
        };
    }

    /**
     * The side named by a Cr/Dr marker next to the number ("500 Cr", "500.00Dr.", "CR 500").
     * SBI-style single-letter C/D is only read as the very end of the text ("1,234.00 C"),
     * since a "₹" extracted from some PDFs comes through as a "C" before the number.
     *
     * @return 'debit'|'credit'|null
     */
    private function marker(string $before, string $after): ?string
    {
        $currency = self::CURRENCY;

        $found = preg_match('/^\s*(CR|DR)(?![A-Za-z])/i', $after, $match)
            || preg_match('/^\s*([CD])\s*\.?\s*$/i', $after, $match)
            || preg_match("/(?<![A-Za-z])(CR|DR)\\.?\\s*{$currency}$/i", $before, $match);

        if (! $found) {
            return null;
        }

        return str_starts_with(strtoupper($match[1]), 'C') ? self::CREDIT : self::DEBIT;
    }

    /**
     * @return 'debit'|'credit'|null
     */
    private function indicatorSide(mixed $indicator): ?string
    {
        if (! is_string($indicator)) {
            return null;
        }

        return match (strtoupper((string) preg_replace('/[^A-Za-z]/', '', $indicator))) {
            'CR', 'C', 'CREDIT', 'DEPOSIT' => self::CREDIT,
            'DR', 'D', 'DEBIT', 'WITHDRAWAL' => self::DEBIT,
            default => null,
        };
    }
}
