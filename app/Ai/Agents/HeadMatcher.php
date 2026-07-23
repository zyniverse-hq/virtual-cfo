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
#[MaxTokens(4096)]
#[Temperature(0.2)]
#[Timeout(120)]
class HeadMatcher implements Agent, HasMiddleware, HasStructuredOutput
{
    use Promptable;

    protected string $chartOfAccounts = '';

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
     * Get the model to use for head matching.
     */
    public function model(): string
    {
        return config('ai.models.matching', 'mistralai/mistral-large-latest');
    }

    /**
     * Set the chart of accounts context (format: "ID: Name (Group)" per line).
     */
    public function withChartOfAccounts(string $chartOfAccounts): static
    {
        $this->chartOfAccounts = $chartOfAccounts;

        return $this;
    }

    public function instructions(): Stringable|string
    {
        $base = <<<'INSTRUCTIONS'
        You are an Indian accounting expert familiar with Tally ERP accounting heads.

        Given a list of transaction descriptions from bank/credit card statements and a chart of accounts,
        suggest the most appropriate account head for each transaction.

        Rules:
        - Match based on the nature of the transaction (salary, rent, utilities, vendor payments, etc.)
        - Always return the account head ID (suggested_head_id) from the chart of accounts
        - Also return the account head name (suggested_head_name) for readability
        - Provide a confidence score between 0 and 1 for each match
        - Provide brief reasoning for each suggestion
        - If no good match exists, suggest the closest head with a low confidence score
        - Consider Indian business context (GST, TDS, etc.)

        The chart of accounts is provided in the format "ID: Name (Group)".
        Always use the numeric ID from this list for suggested_head_id.
        INSTRUCTIONS;

        if ($this->chartOfAccounts) {
            $base .= "\n\nAvailable Account Heads:\n".$this->chartOfAccounts;
        }

        return $base;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'matches' => $schema->array()->items($schema->object([
                'transaction_id' => $schema->integer()->required(),
                'suggested_head_id' => $schema->integer()->required(),
                'suggested_head_name' => $schema->string()->required(),
                'confidence' => $schema->number()->required(),
                'reasoning' => $schema->string()->required(),
            ]))->required(),
        ];
    }
}
