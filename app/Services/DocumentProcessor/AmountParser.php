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

    /**
     * Parse a raw amount into its absolute value, its sign ("-" also covers brackets)
     * and the side named by a Cr/Dr marker. Returns null for empty or placeholder values.
     *
     * @return ParsedAmount|null
     */
    public function parse(mixed $value): ?array
    {
        if (is_int($value) || is_float($value)) {
            return [
                'amount' => (string) abs($value),
                'sign' => $value < 0 ? '-' : null,
                'marker' => null,
            ];
        }

        if (! is_string($value) || ! preg_match('/\d[\d,]*(?:\.\d+)?|\.\d+/', $value, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        [$number, $offset] = $match[0];
        $before = substr($value, 0, $offset);
        $after = substr($value, $offset + strlen($number));

        $sign = match (true) {
            str_contains($before, '-'),
            str_starts_with(trim($after), '-'),
            str_contains($before, '(') && str_contains($after, ')') => '-',
            str_contains($before, '+') => '+',
            default => null,
        };

        return [
            'amount' => str_replace(',', '', $number),
            'sign' => $sign,
            'marker' => $this->marker($after, allowSingleLetter: true) ?? $this->marker($before),
        ];
    }

    /**
     * Resolve the debit and credit amounts of a transaction.
     *
     * An amount stays in the debit or credit column it came from unless a Cr/Dr marker on
     * it, or a marker or sign on the same value in the single amount, names the other side.
     * When both columns are empty, the single amount is split by the indicator column,
     * then by its own marker or sign, defaulting to debit.
     *
     * A "+" means credit. What a "-" means depends on the statement: money out (debit) on a
     * bank account, but a payment or refund that reduces the amount owed (credit) on a card.
     *
     * With $amountDecides (single-column statements such as credit cards), the single amount
     * alone decides the side whenever it is present, ignoring which column it was put in.
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
        bool $amountDecides = false,
    ): array {
        $resolved = [self::DEBIT => null, self::CREDIT => null];
        $single = $this->parseNonZero($amount);
        $singleSide = $single !== null ? $this->side($single, $negativeSide) : null;

        $columns = $amountDecides && $single !== null
            ? []
            : array_filter(array_map($this->parseNonZero(...), [self::DEBIT => $debit, self::CREDIT => $credit]));

        // Both columns filled (e.g. a parser's "-1" placeholder in the unused one): keep the one matching the amount.
        if (count($columns) === 2 && $single !== null) {
            $columns = array_filter($columns, fn (array $parsed): bool => (float) $parsed['amount'] === (float) $single['amount']) ?: $columns;
        }

        if (count($columns) === 2) {
            return [self::DEBIT => $columns[self::DEBIT]['amount'], self::CREDIT => $columns[self::CREDIT]['amount']];
        }

        if (count($columns) === 1) {
            $column = array_key_first($columns);
            $value = $columns[$column]['amount'];
            $sameAmount = $single !== null && (float) $single['amount'] === (float) $value;

            $resolved[$columns[$column]['marker'] ?? ($sameAmount ? $singleSide : null) ?? $column] = $value;

            return $resolved;
        }

        if ($single !== null) {
            $resolved[$this->indicatorSide($indicator) ?? $singleSide ?? self::DEBIT] = $single['amount'];
        }

        return $resolved;
    }

    /**
     * Parse a running balance, keeping it negative only when written with a minus sign or brackets.
     */
    public function parseBalance(mixed $value): ?string
    {
        $parsed = $this->parse($value);

        if ($parsed === null) {
            return null;
        }

        return $parsed['sign'] === '-' ? "-{$parsed['amount']}" : $parsed['amount'];
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
     * The side named by a standalone Cr/Dr marker ("500 Cr", "500.00Dr.", "CR 500").
     * SBI-style single-letter C/D markers are only read after the number ("1,234.00 C"),
     * since a "₹" extracted from some PDFs comes through as a "C" before it.
     *
     * @return 'debit'|'credit'|null
     */
    private function marker(string $text, bool $allowSingleLetter = false): ?string
    {
        $pattern = $allowSingleLetter ? '/(?<![A-Z])(CR|DR|C|D)(?![A-Z])/i' : '/(?<![A-Z])(CR|DR)(?![A-Z])/i';

        if (! preg_match($pattern, $text, $match)) {
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
