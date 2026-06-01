<?php

namespace App\Services\DocumentProcessor;

use App\Ai\Agents\InvoiceParser;
use App\Ai\Agents\StatementParser;
use App\Enums\ImportStatus;
use App\Enums\MappingType;
use App\Enums\StatementType;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Services\DisplayNameGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class DocumentProcessor
{
    public function __construct(
        private PdfDecryptionService $decryptionService,
        private DisplayNameGenerator $displayNameGenerator,
    ) {}

    /**
     * Process an imported file by detecting its format and routing to the appropriate parser.
     */
    public function process(ImportedFile $file): void
    {
        /** @var ImportStatus $status */
        $status = $file->status;

        if ($status === ImportStatus::Skipped) {
            return;
        }

        $file->update(['status' => ImportStatus::Processing]);

        // Clear any transactions from a previous attempt to ensure idempotent retries
        if ($file->transactions()->exists()) {
            $file->transactions()->delete();
        }

        $format = $this->detectFormat($file);

        match ($format) {
            'csv', 'xlsx' => $this->parseStructured($file),
            'pdf' => $this->parsePdf($file),
            default => throw new \RuntimeException("Unsupported file format: {$format}"),
        };
    }

    /**
     * Detect file format from the original filename extension.
     */
    public function detectFormat(ImportedFile $file): string
    {
        $extension = strtolower(pathinfo($file->original_filename, PATHINFO_EXTENSION));

        $supportedFormats = ['pdf', 'csv', 'xlsx'];

        if (! in_array($extension, $supportedFormats)) {
            throw new \RuntimeException(
                "Unsupported file extension: .{$extension}. Supported: ".implode(', ', $supportedFormats)
            );
        }

        return $extension;
    }

    /**
     * Parse structured files (CSV/XLSX) programmatically via Maatwebsite Excel.
     */
    protected function parseStructured(ImportedFile $file): void
    {
        $import = new StructuredFileImport;

        Excel::import($import, $file->file_path, 'local');

        $rows = $import->getRows();

        if (empty($rows)) {
            $file->update([
                'status' => ImportStatus::Failed,
                'error_message' => 'No data rows found in the file.',
            ]);

            return;
        }

        $imported = 0;

        DB::transaction(function () use ($file, $rows, &$imported) {
            foreach ($rows as $row) {
                $normalized = $this->normalizeStructuredRow($row);

                if ($normalized['date'] === null) {
                    Log::warning('Skipping row with unparseable date in structured import', [
                        'file_id' => $file->id,
                        'row' => $row,
                    ]);

                    continue;
                }

                Transaction::create([
                    'company_id' => $file->company_id,
                    'imported_file_id' => $file->id,
                    'date' => $normalized['date'],
                    'description' => $normalized['description'] ?? '',
                    'reference_number' => $normalized['reference'] ?? null,
                    'debit' => $normalized['debit'],
                    'credit' => $normalized['credit'],
                    'balance' => $normalized['balance'],
                    'mapping_type' => MappingType::Unmapped,
                    'raw_data' => $row,
                    'bank_format' => $file->bank_name,
                ]);

                $imported++;
            }

            $file->update([
                'status' => ImportStatus::Completed,
                'total_rows' => $imported,
                'mapped_rows' => 0,
                'processed_at' => now(),
            ]);
        });
    }

    /**
     * Normalize a structured row from CSV/XLSX to the expected transaction format.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function normalizeStructuredRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[strtolower(trim((string) $key))] = $value;
        }

        $rawDate = $this->extractField($normalized, ['date', 'transaction_date', 'txn_date', 'value_date', 'posting_date']);

        return [
            'date' => $this->parseDateField($rawDate),
            'description' => $this->extractField($normalized, ['description', 'narration', 'particulars', 'details', 'transaction_description']),
            'reference' => $this->extractField($normalized, ['reference', 'ref', 'reference_number', 'ref_no', 'cheque_no', 'chq_no']),
            'debit' => $this->extractNumericField($normalized, ['debit', 'debit_amount', 'withdrawal', 'withdrawals', 'dr']),
            'credit' => $this->extractNumericField($normalized, ['credit', 'credit_amount', 'deposit', 'deposits', 'cr']),
            'balance' => $this->extractNumericField($normalized, ['balance', 'closing_balance', 'running_balance', 'available_balance']),
        ];
    }

    /**
     * Parse a date value that may be an Excel serial number, a DateTime object, or a date string.
     *
     * Returns a Carbon date string (Y-m-d) on success, or null if the value cannot be parsed.
     */
    protected function parseDateField(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTime) {
            return Carbon::instance($value)->toDateString();
        }

        if (is_numeric($value)) {
            try {
                $dateTime = ExcelDate::excelToDateTimeObject((float) $value);

                return Carbon::instance($dateTime)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extract a field value by trying multiple possible column names.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $possibleKeys
     */
    protected function extractField(array $row, array $possibleKeys): mixed
    {
        foreach ($possibleKeys as $key) {
            if (isset($row[$key]) && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    /**
     * Extract a numeric field, cleaning currency formatting.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $possibleKeys
     */
    protected function extractNumericField(array $row, array $possibleKeys): ?string
    {
        $value = $this->extractField($row, $possibleKeys);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value === 0.0) {
            return null;
        }

        $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return $cleaned !== '' && $cleaned !== '0' ? $cleaned : null;
    }

    /**
     * Parse PDF files — decrypt if needed, then route to the appropriate AI agent.
     */
    protected function parsePdf(ImportedFile $file): void
    {
        $filePath = $file->file_path;
        $decryptedPath = null;

        if ($this->decryptionService->isPasswordProtected($filePath)) {
            $decryptedPath = $this->decryptPasswordProtectedPdf($file);

            if ($decryptedPath === null) {
                return;
            }

            $filePath = $decryptedPath;
        }

        try {
            /** @var StatementType $statementType */
            $statementType = $file->statement_type;

            match ($statementType) {
                StatementType::Bank, StatementType::CreditCard => $this->parsePdfStatement($file, $filePath),
                StatementType::Invoice => $this->parsePdfInvoice($file, $filePath),
            };
        } finally {
            if ($decryptedPath !== null) {
                Storage::disk(PdfDecryptionService::STORAGE_DISK)->delete($decryptedPath);
            }
        }
    }

    /**
     * Attempt to decrypt a password-protected PDF using gathered passwords.
     *
     * Returns the decrypted file path on success, or null if no passwords work
     * (sets status to NeedsPassword).
     */
    protected function decryptPasswordProtectedPdf(ImportedFile $file): ?string
    {
        if (! $this->decryptionService->isQpdfAvailable()) {
            $file->update([
                'status' => ImportStatus::NeedsPassword,
                'error_message' => 'This PDF is password-protected but the decryption tool (qpdf) is not installed on the server.',
            ]);

            return null;
        }

        $passwords = $this->gatherPasswords($file);

        foreach ($passwords as $password) {
            try {
                return $this->decryptionService->decrypt($file->file_path, $password);
            } catch (\RuntimeException) {
                continue;
            }
        }

        $file->update([
            'status' => ImportStatus::NeedsPassword,
            'error_message' => 'This PDF is password-protected. Please set the PDF password on the linked bank account or credit card, then re-process.',
        ]);

        return null;
    }

    /**
     * Gather password candidates in priority order:
     * 1. Manual password from source_metadata
     * 2. Stored password from linked BankAccount/CreditCard
     * 3. Stored password from email-context matched BankAccount/CreditCard
     *
     * @return array<int, string>
     */
    protected function gatherPasswords(ImportedFile $file): array
    {
        $passwords = [];

        /** @var array<string, mixed>|null $metadata */
        $metadata = $file->source_metadata;
        if (! empty($metadata['manual_password'])) {
            $passwords[] = $metadata['manual_password'];
        }

        $storedPassword = $this->resolveStoredPassword($file);
        if ($storedPassword !== null) {
            $passwords[] = $storedPassword;
        }

        return array_values(array_unique(array_filter($passwords)));
    }

    /**
     * Resolve stored password from linked account first, then try email context matching.
     */
    protected function resolveStoredPassword(ImportedFile $file): ?string
    {
        $linkedPassword = $this->getLinkedAccountPassword($file);
        if ($linkedPassword) {
            return $linkedPassword;
        }

        $matched = $this->matchAccountFromEmailContext($file);
        if ($matched instanceof BankAccount) {
            $file->update(['bank_account_id' => $matched->id]);

            return $matched->pdf_password;
        }

        if ($matched instanceof CreditCard) {
            $file->update(['credit_card_id' => $matched->id]);

            return $matched->pdf_password;
        }

        return null;
    }

    /**
     * Get password from an already-linked BankAccount or CreditCard.
     */
    protected function getLinkedAccountPassword(ImportedFile $file): ?string
    {
        if ($file->bank_account_id) {
            $file->loadMissing('bankAccount');
            $password = $file->bankAccount?->pdf_password;
            if ($password) {
                return $password;
            }
        }

        if ($file->credit_card_id) {
            $file->loadMissing('creditCard');
            $password = $file->creditCard?->pdf_password;
            if ($password) {
                return $password;
            }
        }

        return null;
    }

    /**
     * Try to match a BankAccount or CreditCard from email subject/body context.
     */
    protected function matchAccountFromEmailContext(ImportedFile $file): BankAccount|CreditCard|null
    {
        /** @var array<string, mixed>|null $metadata */
        $metadata = $file->source_metadata;

        $searchText = trim(($metadata['subject'] ?? '').' '.($metadata['body_text'] ?? ''));

        if ($searchText === '') {
            return null;
        }

        $searchTextLower = strtolower($searchText);

        $bankAccounts = BankAccount::where('company_id', $file->company_id)
            ->whereNotNull('pdf_password')
            ->where('is_active', true)
            ->get();

        foreach ($bankAccounts as $account) {
            if (str_contains($searchTextLower, strtolower($account->name))) {
                return $account;
            }
        }

        $creditCards = CreditCard::where('company_id', $file->company_id)
            ->whereNotNull('pdf_password')
            ->where('is_active', true)
            ->get();

        foreach ($creditCards as $card) {
            if (str_contains($searchTextLower, strtolower($card->name))) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Parse a PDF bank/credit card statement via StatementParser agent with document attachment.
     */
    protected function parsePdfStatement(ImportedFile $file, ?string $filePath = null): void
    {
        $filePath ??= $file->file_path;

        $response = StatementParser::make()->prompt(
            'Parse all transactions from this bank statement. Extract every single transaction row.',
            attachments: [Document::fromStorage($filePath, disk: 'local')],
        );

        if (! isset($response['transactions']) || ! is_array($response['transactions'])) {
            Log::warning('StatementParser returned invalid response', [
                'file_id' => $file->id,
                'response' => $response,
            ]);

            throw new \RuntimeException(
                'StatementParser response missing valid transactions array.'
            );
        }

        $bankName = $response['bank_name'] ?? null;
        $accountNumber = $response['account_number'] ?? null;
        $accountHolderName = ($response['account_holder_name'] ?? null) ?: null;
        $statementPeriod = $response['statement_period'] ?? null;
        $cardVariant = $response['card_variant'] ?? null;
        $transactions = $response['transactions'];
        $previousBalance = is_numeric($response['previous_balance'] ?? null)
            ? (float) $response['previous_balance']
            : null;

        if (empty($transactions)) {
            $file->update([
                'status' => ImportStatus::Failed,
                'error_message' => 'No transactions found in the statement.',
            ]);

            return;
        }

        DB::transaction(function () use ($file, $bankName, $accountNumber, $accountHolderName, $statementPeriod, $cardVariant, $transactions, $previousBalance) {
            $fileUpdates = [
                'status' => ImportStatus::Completed,
                'total_rows' => count($transactions),
                'mapped_rows' => 0,
                'processed_at' => now(),
            ];

            if ($accountHolderName !== null) {
                $fileUpdates['account_holder_name'] = $accountHolderName;
            }

            if ($previousBalance !== null) {
                $fileUpdates['opening_balance'] = $previousBalance;
            }

            if ($bankName) {
                $fileUpdates['bank_name'] = $bankName;

                /** @var StatementType $statementType */
                $statementType = $file->statement_type;

                $fkUpdate = $statementType === StatementType::CreditCard
                    ? $this->autoMatchFinancialAccount($file, $bankName, CreditCard::class, 'credit_card_id', $accountNumber)
                    : $this->autoMatchFinancialAccount($file, $bankName, BankAccount::class, 'bank_account_id', $accountNumber);

                if ($fkUpdate !== null) {
                    $fileUpdates = array_merge($fileUpdates, $fkUpdate);
                }
            }

            if ($accountNumber) {
                $fileUpdates['account_number'] = $accountNumber;
            }

            if ($statementPeriod !== null) {
                $fileUpdates['statement_period'] = $statementPeriod;
            }

            if ($cardVariant !== null) {
                $fileUpdates['card_variant'] = $cardVariant;
            }

            foreach ($transactions as $row) {
                Transaction::create([
                    'company_id' => $file->company_id,
                    'imported_file_id' => $file->id,
                    'date' => $this->parseTransactionDate($row['date']),
                    'description' => $row['description'] ?? '',
                    'reference_number' => $row['reference'] ?? null,
                    'debit' => isset($row['debit']) && (float) $row['debit'] > 0 ? (string) $row['debit'] : null,
                    'credit' => isset($row['credit']) && (float) $row['credit'] > 0 ? (string) $row['credit'] : null,
                    'balance' => isset($row['balance']) ? (string) $row['balance'] : null,
                    'mapping_type' => MappingType::Unmapped,
                    'raw_data' => $row,
                    'bank_format' => $bankName,
                ]);
            }

            /** @var StatementType $statementType */
            $statementType = $file->statement_type;

            if ($statementType === StatementType::CreditCard && $previousBalance !== null && $previousBalance > 0) {
                $syntheticDate = $this->resolvePreviousBalanceDate($statementPeriod, $transactions);

                Transaction::create([
                    'company_id' => $file->company_id,
                    'imported_file_id' => $file->id,
                    'date' => $syntheticDate,
                    'description' => 'Previous Balance',
                    'debit' => (string) $previousBalance,
                    'credit' => null,
                    'balance' => null,
                    'mapping_type' => MappingType::Unmapped,
                    'raw_data' => ['previous_balance' => $previousBalance],
                    'bank_format' => $bankName,
                    'is_synthetic' => true,
                ]);

                $fileUpdates['total_rows'] = count($transactions) + 1;
            }

            $file->fill($fileUpdates);

            if (! $file->relationLoaded('creditCard')) {
                $file->load('creditCard');
            }

            if (blank($file->display_name)) {
                $file->display_name = $this->displayNameGenerator->generate($file);
            }

            $file->save();
        });
    }

    private function parseTransactionDate(string $date): Carbon
    {
        $date = trim($date);

        // D/M/YYYY, DD/MM/YYYY, or DD-MM-YYYY (Indian format — must check before Carbon::parse which defaults to MM/DD)
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $date, $m)) {
            try {
                return Carbon::createFromFormat('d/m/Y', "{$m[1]}/{$m[2]}/{$m[3]}");
            } catch (\Exception) {
            }
        }

        // DD-Mon-YYYY or DD Mon YYYY (e.g. "05-Apr-2026", "05 Apr 2026")
        if (preg_match('/^(\d{1,2})[\s\-]([A-Za-z]{3,9})[\s\-](\d{4})$/', $date)) {
            try {
                return Carbon::createFromFormat('d M Y', preg_replace('/[\-]/', ' ', $date));
            } catch (\Exception) {
            }
        }

        // YYYY-MM-DD (unambiguous ISO format — safe to parse directly)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Carbon::parse($date);
        }

        return Carbon::parse($date);
    }

    /**
     * Resolve the date for a Previous Balance synthetic transaction.
     *
     * Attempts to extract the start date from the statement period string,
     * supporting ISO, DD/MM/YYYY, DD-MM-YYYY, DD Mon YYYY, and DD-Mon-YYYY formats.
     * Falls back to the earliest transaction date if parsing fails.
     *
     * @param  array<int, array<string, mixed>>  $transactions
     */
    protected function resolvePreviousBalanceDate(?string $statementPeriod, array $transactions): Carbon
    {
        if ($statementPeriod !== null) {
            $firstDate = $this->extractFirstDateFromPeriod($statementPeriod);

            if ($firstDate !== null) {
                try {
                    return $this->parseTransactionDate($firstDate);
                } catch (\Throwable) {
                    // fallthrough to transaction date fallback
                }
            }
        }

        $dates = array_filter(array_column($transactions, 'date'));
        if (! empty($dates)) {
            sort($dates);

            return Carbon::parse(reset($dates));
        }

        return now();
    }

    /**
     * Extract the first date substring from a statement period string.
     *
     * Matches human-readable (MonthName DD, YYYY; DD Mon YYYY), ISO (YYYY-MM-DD),
     * and Indian slash/dash formats (DD/MM/YYYY, DD-MM-YYYY) in that order.
     */
    private function extractFirstDateFromPeriod(string $statementPeriod): ?string
    {
        // MonthName DD, YYYY (e.g. "March 6, 2026") — full English month name, day-after
        if (preg_match('/[A-Za-z]{3,9}\s+\d{1,2},?\s+\d{4}/', $statementPeriod, $m)) {
            return $m[0];
        }

        // DD Mon YYYY or DD-Mon-YYYY (e.g. "01 Apr 2026", "01-Apr-2026")
        if (preg_match('/\d{1,2}[\s\-][A-Za-z]{3,9}[\s\-]\d{4}/', $statementPeriod, $m)) {
            return $m[0];
        }

        // YYYY-MM-DD (ISO — check before DD-MM-YYYY to avoid ambiguity)
        if (preg_match('/\d{4}-\d{2}-\d{2}/', $statementPeriod, $m)) {
            return $m[0];
        }

        // DD/MM/YYYY or DD-MM-YYYY (Indian numeric formats)
        if (preg_match('/\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}/', $statementPeriod, $m)) {
            return $m[0];
        }

        return null;
    }

    /**
     * Match or create a financial account (BankAccount or CreditCard) and return the FK update.
     *
     * @param  class-string<BankAccount|CreditCard>  $modelClass
     * @return array<string, int>|null
     */
    protected function autoMatchFinancialAccount(ImportedFile $file, string $bankName, string $modelClass, string $fkColumn, ?string $accountNumber = null): ?array
    {
        if ($file->{$fkColumn}) {
            return null;
        }

        $numberColumn = $modelClass === CreditCard::class ? 'card_number' : 'account_number';

        $creationDefaults = [];
        if ($accountNumber) {
            $creationDefaults[$numberColumn] = $accountNumber;
        }

        $account = $modelClass::firstOrCreate(
            ['company_id' => $file->company_id, 'name' => $bankName],
            $creationDefaults,
        );

        return [$fkColumn => $account->id];
    }

    /**
     * Parse a PDF invoice via InvoiceParser agent with document attachment.
     */
    protected function parsePdfInvoice(ImportedFile $file, ?string $filePath = null): void
    {
        $filePath ??= $file->file_path;

        $response = InvoiceParser::make()->prompt(
            'Parse all data from this vendor invoice. Extract every field including line items, GST breakup, and TDS if present.',
            attachments: [Document::fromStorage($filePath, disk: 'local')],
        );

        if (empty($response['invoice_number']) || empty($response['vendor_name'])) {
            Log::warning('InvoiceParser returned invalid response', [
                'file_id' => $file->id,
                'response' => $response,
            ]);

            throw new \RuntimeException(
                'InvoiceParser response missing required fields (vendor_name, invoice_number).'
            );
        }

        $vendorName = $response['vendor_name'];
        $invoiceNumber = $response['invoice_number'];
        $invoiceDate = $response['invoice_date'] ?? null;
        $totalAmount = $response['total_amount'] ?? null;

        $file->loadMissing('company');
        $currency = $response['currency'] ?? $file->company?->currency ?? 'INR';

        /** @var StructuredAgentResponse $response */
        $rawData = $response->toArray();

        DB::transaction(function () use ($file, $rawData, $vendorName, $invoiceNumber, $invoiceDate, $totalAmount, $currency) {
            $file->update(['bank_name' => $vendorName]);

            Transaction::create([
                'company_id' => $file->company_id,
                'imported_file_id' => $file->id,
                'date' => $invoiceDate ? Carbon::parse($invoiceDate) : now(),
                'description' => $invoiceNumber.' - '.$vendorName,
                'reference_number' => $invoiceNumber,
                'debit' => $totalAmount !== null ? (string) (int) $totalAmount : null,
                'credit' => null,
                'balance' => null,
                'currency' => $currency,
                'mapping_type' => MappingType::Unmapped,
                'raw_data' => $rawData,
                'bank_format' => $vendorName,
            ]);

            $updates = [
                'status' => ImportStatus::Completed,
                'total_rows' => 1,
                'mapped_rows' => 0,
                'processed_at' => now(),
            ];

            if (blank($file->display_name)) {
                $updates['display_name'] = $this->displayNameGenerator->generate($file);
            }

            $file->update($updates);
        });
    }
}
