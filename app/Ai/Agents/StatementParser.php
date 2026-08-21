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
        - For each transaction, extract: date, description, debit, credit, and running balance
        - Return each transaction date exactly as it appears in the source document (e.g. "10/03/2026", "05-Apr-2026", "17/03/2026"). Do NOT reformat or convert dates.
        - CRITICAL RULE FOR SINGLE-COLUMN AMOUNTS: If both charges and refunds are in a single "Amount" column, an amount with a "CR" suffix (e.g. "9,148.42 CR") is a refund/payment and MUST be placed in the `credit` field. Amounts with no suffix or a "DR" suffix are standard charges and MUST be placed in the `debit` field. Do NOT guess based on the description.
        - CRITICAL RULE — NEVER DROP AN AMOUNT: Every transaction row you return MUST have a monetary amount in either `debit` or `credit`. Never return a transaction with both `debit` and `credit` empty. Payments, refunds, cashback, rewards, reversals, and lines such as "Payment Received", "Autodebit Payment Recd.", or "transaction cashback" are money coming IN and MUST be captured in `credit`. If you can see a transaction row but are unsure which column its amount belongs in, still capture the amount using the CR/DR rule above — never omit it.
        - Strip "CR" or "DR" suffixes, currency symbols, and commas when extracting amounts. The final values in the debit and credit fields must be purely positive absolute numbers.
        - If a non-amount field (reference or balance) is not present, use null. This does NOT apply to the amount — every row must still have a debit or credit per the rule above.
        - Extract reference numbers where available
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
                'debit' => $schema->number(),
                'credit' => $schema->number(),
                'balance' => $schema->number(),
            ]))->required(),
        ];
    }
}
