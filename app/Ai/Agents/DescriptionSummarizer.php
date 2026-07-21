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
#[MaxTokens(2000)]
#[Temperature(0.1)]
#[Timeout(120)]
class DescriptionSummarizer implements Agent, HasMiddleware, HasStructuredOutput
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
     * Get the model to use for summarization.
     */
    public function model(): string
    {
        return config('ai.models.summarizer', 'google/gemini-flash-1.5');
    }

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You are an assistant designed to summarize bank and credit card transaction descriptions.
        You will be provided with a JSON array of transaction objects containing `id` and `description`.
        
        Your task is to extract the core party (who was paid or who credited the money) and return a very short, concise summary (1-3 words max).
        For example:
        - "UPI/123456789/Payment to John Doe/HDFC Bank" -> "Paid John Doe" or "John Doe"
        - "NEFT-12345-RELIANCE INDUSTRIES" -> "Reliance Industries"
        - "POS 1234 * STARBUCKS" -> "Starbucks"
        - "SALARY FOR MARCH 2026" -> "Salary"
        
        Return a JSON array of summarized descriptions matching the exact `transaction_id`s provided.
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summaries' => $schema->array()->items($schema->object([
                'transaction_id' => $schema->integer()->required(),
                'short_description' => $schema->string()->required(),
            ]))->required(),
        ];
    }
}
