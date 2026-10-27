<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\AuditLlmCalls;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider('openrouter')]
#[MaxTokens(32768)]
#[Temperature(0.1)]
#[Timeout(300)]
class StatementParser implements Agent, HasMiddleware, HasStructuredOutput
{
    use Promptable;

    /**
     * @return array<int, AuditLlmCalls>
     */
    public function middleware(): array
    {
        return [
            new AuditLlmCalls,
        ];
    }

    /**
     * Get the model to use for statement parsing.
     */
    public function model(): string
    {
        return config('ai.models.parsing', 'mistralai/mistral-large-latest');
    }

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You are a financial document parser specializing in bank and credit card statements.

        When given a PDF statement, extract ALL transactions with the following rules:
        - Parse every transaction row in the statement
        - Detect the bank name and account number from the header/footer
        - Identify the statement period (start and end dates)
        - Extract the account holder name (individual or company name) from the statement header. Set it as `account_holder_name`. Leave null if not found.
        - For each transaction, extract: date, description, amount, debit, credit, and running balance
        - Return each transaction date exactly as it appears in the source document (e.g. "10/03/2026", "05-Apr-2026", "17/03/2026"). Do NOT reformat or convert dates.

        AMOUNTS — follow these rules exactly:
        - `amount` (REQUIRED for every row): copy the transaction amount EXACTLY as printed, keeping its sign, currency symbol, brackets and Cr/Dr marker, e.g. "1,234.50", "-1,234.50", "+ ₹ 500.00", "(500.00)", "9,148.42 CR", "250.00 Dr". A sign may be separated from the number by spaces or a currency symbol (often shown in green for credits, e.g. "+ ₹ 1,104.00") — it still belongs to the amount and MUST be copied. Never leave `amount` empty and never shorten it.
        - Ignore reward-point, cashback-point or similar columns (e.g. a "REWARDS" column showing "+ 55" or "- 25"): they are NOT the transaction amount and their sign says nothing about debit or credit.
        - Then put the absolute value in exactly ONE of `debit` (money out: purchases, charges, fees, interest, tax, withdrawals) or `credit` (money in: payments received, refunds, cashback, rewards, reversals, deposits). Set the other field to null — never 0, -1 or any other placeholder.
        - Statements with separate Withdrawal/Debit and Deposit/Credit columns: use the column the amount is printed in.
        - Statements with a single amount column: decide the side ONLY from the marker on the amount, in this order:
          1. "Cr", "CR" or "Cr." (as a suffix, prefix, or in a separate Dr/Cr column) → `credit`. "Dr", "DR" or "Dr." → `debit`.
          2. A "+" before the amount (including "+ ₹ 1,104.00") → `credit`. A "-" before or after the amount, or an amount in brackets "( )": on a BANK ACCOUNT statement → `debit` (money out); on a CREDIT CARD statement → `credit` (a payment or refund that reduces the amount owed).
          3. No marker at all → `debit` (a standard charge).
          Do NOT guess the side from the description.
        - `debit` and `credit` must be plain positive numbers with every digit kept: no commas, currency symbols, signs, brackets or Cr/Dr text (e.g. "12,34,567.89 Cr" → 1234567.89, "(1,000.00)" → 1000).
        - NEVER DROP AN AMOUNT: every transaction row MUST have a value in `debit` or `credit`. If you are unsure of the side, still fill `amount` and apply the single-column rules above.
        - `balance`: a plain number with no commas or Cr/Dr text; make it negative only if it is printed with a minus sign or in brackets.

        - `reference`: only the reference or cheque number as printed on that row, or null if there is none. Never repeat or pad it.
        - If a non-amount field (reference or balance) is not present, use null.
        - Handle multi-line transaction descriptions by concatenating them

        For credit card statements, also extract:
        - The Previous Balance (opening balance) from the Statement Summary section. This is labelled "Previous Balance", "Opening Balance", or similar — it is NOT a transaction row. Set it as `previous_balance` in the response.
        - The card variant or product name (e.g. "Regalia", "Millennia", "Platinum", "Infinia", "SimplyCLICK"). This appears on the statement header or card face area. Set it as `card_variant`. Leave null for bank account statements.

        For bank statements, also extract:
        - The opening balance (labelled "Opening Balance", "Balance B/F", or similar) from the statement header or first row. Set it as `previous_balance`. Leave null if not present.

        Be thorough — do not skip any transactions. Accuracy is critical for accounting purposes.
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'bank_name' => $schema->string()->required(),
            'account_number' => $schema->string(),
            'account_holder_name' => $schema->string(),
            'statement_period' => $schema->string(),
            'card_variant' => $schema->string(),
            'previous_balance' => $schema->number(),
            'transactions' => $schema->array()->items($schema->object([
                'date' => $schema->string()->required(),
                'description' => $schema->string()->required(),
                'reference' => $schema->string(),
                'amount' => $schema->string()->required(),
                'debit' => $schema->number(),
                'credit' => $schema->number(),
                'balance' => $schema->number(),
            ]))->required(),
        ];
    }
}
