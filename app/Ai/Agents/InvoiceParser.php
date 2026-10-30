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
#[MaxTokens(8192)]
#[Temperature(0.1)]
#[Timeout(180)]
class InvoiceParser implements Agent, HasMiddleware, HasStructuredOutput
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
     * Get the model to use for invoice parsing.
     */
    public function model(): string
    {
        return config('ai.models.parsing', 'openai/gpt-6-luna');
    }

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You are a financial document parser specializing in vendor invoices for Indian businesses.

        When given a PDF invoice, extract ALL fields with the following rules:
        - Extract vendor details: name, GSTIN, invoice number, dates
        - Identify the place of supply to determine GST type (intra-state vs inter-state)
        - For intra-state supply: extract CGST and SGST rates and amounts
        - For inter-state supply: extract IGST rate and amount
        - Extract TDS amount if deducted
        - Parse ALL line items with description, HSN/SAC code, quantity, rate, and amount
        - Extract service_name as the overall service category the vendor provides (e.g., "Website Maintenance", "Cloud Hosting", "IT Consulting"). Infer it from the invoice header, vendor specialization, or line item descriptions — if descriptions follow the pattern "ServiceCategory - Specific Detail", use "ServiceCategory" as service_name
        - Dates should be in YYYY-MM-DD format
        - Amounts should be numeric (no currency symbols or commas)
        - Detect the invoice currency from the document (currency symbols, text like "USD", "EUR", etc.)
        - If no currency symbol or indicator is present, default to "INR"
        - If a field is not present, use null
        - Calculate and verify: base_amount + GST - TDS should approximately equal total_amount

        Be thorough — accuracy is critical for GST filing and Tally journal entries.
        INSTRUCTIONS;
    }

    /**
     * Strict structured-output schema: every object is closed and every field is required,
     * with optional fields nullable (required by OpenAI models, accepted by the others).
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'vendor_name' => $schema->string()->required(),
            'vendor_gstin' => $schema->string()->nullable()->required(),
            'buyer_name' => $schema->string()->nullable()->required(),
            'buyer_gstin' => $schema->string()->nullable()->required(),
            'buyer_address' => $schema->array()->items($schema->string())->nullable()->required(),
            'invoice_number' => $schema->string()->required(),
            'invoice_date' => $schema->string()->required(),
            'due_date' => $schema->string()->nullable()->required(),
            'place_of_supply' => $schema->string()->nullable()->required(),
            'service_name' => $schema->string()->nullable()->required(),
            'hsn_sac' => $schema->string()->nullable()->required(),
            'line_items' => $schema->array()->items($schema->object([
                'description' => $schema->string()->required(),
                'hsn_sac' => $schema->string()->nullable()->required(),
                'quantity' => $schema->number()->nullable()->required(),
                'rate' => $schema->number()->nullable()->required(),
                'amount' => $schema->number()->required(),
            ])->withoutAdditionalProperties())->required(),
            'base_amount' => $schema->number()->required(),
            'cgst_rate' => $schema->number()->nullable()->required(),
            'cgst_amount' => $schema->number()->nullable()->required(),
            'sgst_rate' => $schema->number()->nullable()->required(),
            'sgst_amount' => $schema->number()->nullable()->required(),
            'igst_rate' => $schema->number()->nullable()->required(),
            'igst_amount' => $schema->number()->nullable()->required(),
            'tds_amount' => $schema->number()->nullable()->required(),
            'total_amount' => $schema->number()->required(),
            'currency' => $schema->string()->required(),
            'amount_in_words' => $schema->string()->nullable()->required(),
        ];
    }
}
