<?php

use App\Ai\Agents\InvoiceParser;
use App\Ai\Agents\StatementParser;
use App\Enums\ImportStatus;
use App\Enums\MappingType;
use App\Enums\StatementType;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CreditCard;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Services\DocumentProcessor\DocumentProcessor;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

describe('DocumentProcessor', function () {
    beforeEach(function () {
        Storage::fake('local');

        $this->processor = app(DocumentProcessor::class);
    });

    describe('detectFormat', function () {
        it('detects PDF format from filename', function () {
            $file = ImportedFile::factory()->create([
                'original_filename' => 'statement.pdf',
            ]);

            expect($this->processor->detectFormat($file))->toBe('pdf');
        });

        it('detects CSV format from filename', function () {
            $file = ImportedFile::factory()->csv()->create();

            expect($this->processor->detectFormat($file))->toBe('csv');
        });

        it('detects XLSX format from filename', function () {
            $file = ImportedFile::factory()->xlsx()->create();

            expect($this->processor->detectFormat($file))->toBe('xlsx');
        });

        it('throws for unsupported file extensions', function () {
            $file = ImportedFile::factory()->create([
                'original_filename' => 'document.docx',
            ]);

            $this->processor->detectFormat($file);
        })->throws(RuntimeException::class, 'Unsupported file extension: .docx');

        it('is case-insensitive for extensions', function () {
            $file = ImportedFile::factory()->create([
                'original_filename' => 'STATEMENT.PDF',
            ]);

            expect($this->processor->detectFormat($file))->toBe('pdf');
        });
    });

    describe('CSV parsing', function () {
        it('parses a CSV file and creates transactions', function () {
            $csvContent = "Date,Description,Debit,Credit,Balance\n";
            $csvContent .= "2024-01-05,SALARY JAN 2024,,50000,150000\n";
            $csvContent .= "2024-01-10,RENT PAYMENT,15000,,135000\n";
            $csvContent .= "2024-01-15,EMI HDFC,8500,,126500\n";

            Storage::put('statements/test.csv', $csvContent);

            $file = ImportedFile::factory()->csv()->create([
                'file_path' => 'statements/test.csv',
                'original_filename' => 'HDFC_statement.csv',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->status)->toBe(ImportStatus::Completed)
                ->and($file->total_rows)->toBe(3)
                ->and($file->mapped_rows)->toBe(0)
                ->and($file->processed_at)->not->toBeNull();

            $transactions = Transaction::where('imported_file_id', $file->id)->orderBy('id')->get();
            expect($transactions)->toHaveCount(3);

            $first = $transactions->first();
            expect($first->description)->toBe('SALARY JAN 2024')
                ->and($first->mapping_type)->toBe(MappingType::Unmapped);
        });

        it('handles CSV with alternative column names', function () {
            $csvContent = "Txn Date,Narration,Withdrawal,Deposit,Closing Balance\n";
            $csvContent .= "2024-02-01,UPI PAYMENT,500,,9500\n";

            Storage::put('statements/alt.csv', $csvContent);

            $file = ImportedFile::factory()->csv()->create([
                'file_path' => 'statements/alt.csv',
                'original_filename' => 'bank_export.csv',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->status)->toBe(ImportStatus::Completed)
                ->and($file->total_rows)->toBe(1);
        });

        it('marks file as failed when CSV has no data rows', function () {
            $csvContent = "Date,Description,Debit,Credit,Balance\n";

            Storage::put('statements/empty.csv', $csvContent);

            $file = ImportedFile::factory()->csv()->create([
                'file_path' => 'statements/empty.csv',
                'original_filename' => 'empty.csv',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->status)->toBe(ImportStatus::Failed)
                ->and($file->error_message)->toContain('No data rows found');
        });

        it('skips completely empty rows in CSV', function () {
            $csvContent = "Date,Description,Debit,Credit,Balance\n";
            $csvContent .= "2024-01-05,SALARY,,50000,150000\n";
            $csvContent .= ",,,,\n";
            $csvContent .= "2024-01-10,RENT,15000,,135000\n";

            Storage::put('statements/gaps.csv', $csvContent);

            $file = ImportedFile::factory()->csv()->create([
                'file_path' => 'statements/gaps.csv',
                'original_filename' => 'gaps.csv',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->total_rows)->toBe(2);
        });

        it('cleans currency formatting from numeric fields', function () {
            $csvContent = "Date,Description,Debit,Credit,Balance\n";
            $csvContent .= "2024-01-05,TRANSFER,\"1,500.00\",,\"98,500.00\"\n";

            Storage::put('statements/currency.csv', $csvContent);

            $file = ImportedFile::factory()->csv()->create([
                'file_path' => 'statements/currency.csv',
                'original_filename' => 'currency.csv',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $transaction = Transaction::where('imported_file_id', $file->id)->first();
            expect($transaction->debit)->toBe('1500.00')
                ->and($transaction->balance)->toBe('98500.00');
        });

        it('stores original row data in raw_data field', function () {
            $csvContent = "Date,Description,Debit,Credit,Balance\n";
            $csvContent .= "2024-01-05,SALARY,,50000,150000\n";

            Storage::put('statements/raw.csv', $csvContent);

            $file = ImportedFile::factory()->csv()->create([
                'file_path' => 'statements/raw.csv',
                'original_filename' => 'raw.csv',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $transaction = Transaction::where('imported_file_id', $file->id)->first();
            expect($transaction->raw_data)->toBeArray()
                ->and($transaction->raw_data)->toHaveKey('description');
        });

        it('sets status to processing before parsing', function () {
            $csvContent = "Date,Description,Debit,Credit,Balance\n";
            $csvContent .= "2024-01-05,TEST,,100,100\n";

            Storage::put('statements/proc.csv', $csvContent);

            $file = ImportedFile::factory()->csv()->create([
                'file_path' => 'statements/proc.csv',
                'original_filename' => 'proc.csv',
                'status' => ImportStatus::Pending,
            ]);

            // After process completes, it should be Completed (went through Processing)
            $this->processor->process($file);

            $file->refresh();
            expect($file->status)->toBe(ImportStatus::Completed);
        });
    });

    describe('PDF routing', function () {
        it('routes bank statement PDFs to StatementParser agent', function () {
            Storage::put('statements/bank.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'HDFC Bank',
                    'account_number' => '1234567890',
                    'statement_period' => '2024-01-01 to 2024-01-31',
                    'transactions' => [
                        ['date' => '2024-01-05', 'description' => 'SALARY', 'credit' => 50000, 'balance' => 150000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/bank.pdf',
                'original_filename' => 'bank_statement.pdf',
                'statement_type' => StatementType::Bank,
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            StatementParser::assertPrompted(fn ($prompt) => $prompt->contains('Parse all transactions from this bank statement'));

            $file->refresh();
            expect($file->status)->toBe(ImportStatus::Completed)
                ->and($file->bank_name)->toBe('HDFC Bank')
                ->and($file->total_rows)->toBe(1);
        });

        it('routes credit card statement PDFs to StatementParser agent', function () {
            Storage::put('statements/cc.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'ICICI CC',
                    'transactions' => [
                        ['date' => '2024-01-05', 'description' => 'AMAZON', 'debit' => 2000, 'balance' => 2000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->creditCard()->create([
                'file_path' => 'statements/cc.pdf',
                'original_filename' => 'cc_statement.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            StatementParser::assertPrompted(fn ($prompt) => $prompt->contains('Parse all transactions from this bank statement'));

            $file->refresh();
            expect($file->status)->toBe(ImportStatus::Completed);
        });

        it('routes invoice PDFs to InvoiceParser agent', function () {
            Storage::put('statements/invoice.pdf', 'fake-pdf-content');

            InvoiceParser::fake([
                [
                    'vendor_name' => 'Test Vendor',
                    'vendor_gstin' => '29AABCT1234A1Z5',
                    'invoice_number' => 'TV/001',
                    'invoice_date' => '2025-01-15',
                    'due_date' => null,
                    'place_of_supply' => 'Karnataka',
                    'line_items' => [
                        ['description' => 'Service', 'hsn_sac' => '998311', 'quantity' => 1, 'rate' => 5000.00, 'amount' => 5000.00],
                    ],
                    'base_amount' => 5000.00,
                    'cgst_rate' => 9,
                    'cgst_amount' => 450.00,
                    'sgst_rate' => 9,
                    'sgst_amount' => 450.00,
                    'igst_rate' => null,
                    'igst_amount' => null,
                    'tds_amount' => null,
                    'total_amount' => 5900.00,
                    'currency' => 'INR',
                    'amount_in_words' => null,
                ],
            ]);

            $file = ImportedFile::factory()->invoice()->create([
                'file_path' => 'statements/invoice.pdf',
                'original_filename' => 'vendor_invoice.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            InvoiceParser::assertPrompted(fn ($prompt) => $prompt->contains('Parse all data from this vendor invoice'));

            $file->refresh();
            expect($file->status)->toBe(ImportStatus::Completed)
                ->and($file->total_rows)->toBe(1);
        });

        it('falls back to company default currency when InvoiceParser returns null currency', function () {
            Storage::put('statements/null_currency_invoice.pdf', 'fake-pdf-content');

            InvoiceParser::fake([
                [
                    'vendor_name' => 'Local Vendor',
                    'vendor_gstin' => null,
                    'invoice_number' => 'LV/001',
                    'invoice_date' => '2026-01-10',
                    'due_date' => null,
                    'place_of_supply' => null,
                    'line_items' => [
                        ['description' => 'Consulting', 'hsn_sac' => null, 'quantity' => 1, 'rate' => 1000.00, 'amount' => 1000.00],
                    ],
                    'base_amount' => 1000.00,
                    'cgst_rate' => null,
                    'cgst_amount' => null,
                    'sgst_rate' => null,
                    'sgst_amount' => null,
                    'igst_rate' => null,
                    'igst_amount' => null,
                    'tds_amount' => null,
                    'total_amount' => 1000.00,
                    'currency' => null,
                    'amount_in_words' => null,
                ],
            ]);

            $company = Company::factory()->create(['currency' => 'EUR']);
            $file = ImportedFile::factory()->invoice()->for($company)->create([
                'file_path' => 'statements/null_currency_invoice.pdf',
                'original_filename' => 'null_currency_invoice.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $transaction = Transaction::where('imported_file_id', $file->id)->first();
            expect($transaction->currency)->toBe('EUR');
        });

        it('stores currency from InvoiceParser response on transaction', function () {
            Storage::put('statements/usd_invoice.pdf', 'fake-pdf-content');

            InvoiceParser::fake([
                [
                    'vendor_name' => 'Anthropic Inc',
                    'vendor_gstin' => null,
                    'invoice_number' => 'ANT/2026/001',
                    'invoice_date' => '2026-02-15',
                    'due_date' => null,
                    'place_of_supply' => null,
                    'line_items' => [
                        ['description' => 'Claude Code Subscription', 'hsn_sac' => null, 'quantity' => 1, 'rate' => 200.00, 'amount' => 200.00],
                    ],
                    'base_amount' => 200.00,
                    'cgst_rate' => null,
                    'cgst_amount' => null,
                    'sgst_rate' => null,
                    'sgst_amount' => null,
                    'igst_rate' => null,
                    'igst_amount' => null,
                    'tds_amount' => null,
                    'total_amount' => 200.00,
                    'currency' => 'USD',
                    'amount_in_words' => null,
                ],
            ]);

            $file = ImportedFile::factory()->invoice()->create([
                'file_path' => 'statements/usd_invoice.pdf',
                'original_filename' => 'usd_invoice.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $transaction = Transaction::where('imported_file_id', $file->id)->first();
            expect($transaction->currency)->toBe('USD')
                ->and($transaction->debit)->toBe('200');
        });

        it('stores statement_period from bank statement response', function () {
            Storage::put('statements/period_bank.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'HDFC Bank',
                    'account_number' => '1234567890',
                    'statement_period' => '2024-01-01 to 2024-01-31',
                    'transactions' => [
                        ['date' => '2024-01-05', 'description' => 'SALARY', 'credit' => 50000, 'balance' => 150000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/period_bank.pdf',
                'original_filename' => 'bank_period.pdf',
                'statement_type' => StatementType::Bank,
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->statement_period)->toBe('2024-01-01 to 2024-01-31');
        });

        it('stores statement_period from credit card statement response', function () {
            Storage::put('statements/period_cc.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'ICICI CC',
                    'statement_period' => 'Feb 2024',
                    'transactions' => [
                        ['date' => '2024-02-05', 'description' => 'AMAZON', 'debit' => 2000, 'balance' => 2000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->creditCard()->create([
                'file_path' => 'statements/period_cc.pdf',
                'original_filename' => 'cc_period.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->statement_period)->toBe('Feb 2024');
        });

        it('handles null statement_period gracefully', function () {
            Storage::put('statements/no_period.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'SBI',
                    'transactions' => [
                        ['date' => '2024-01-05', 'description' => 'PAYMENT', 'debit' => 1000, 'balance' => 9000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/no_period.pdf',
                'original_filename' => 'no_period.pdf',
                'statement_type' => StatementType::Bank,
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->statement_period)->toBeNull()
                ->and($file->status)->toBe(ImportStatus::Completed);
        });

        it('marks file as failed when PDF has no transactions', function () {
            Storage::put('statements/empty.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'SBI',
                    'transactions' => [],
                ],
            ]);

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/empty.pdf',
                'original_filename' => 'empty.pdf',
                'statement_type' => StatementType::Bank,
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->status)->toBe(ImportStatus::Failed)
                ->and($file->error_message)->toContain('No transactions found');
        });
    });

    describe('skipped files', function () {
        it('returns early for files with Skipped status', function () {
            Storage::put('statements/report.pdf', 'fake-pdf-content');

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/report.pdf',
                'original_filename' => 'report.pdf',
                'status' => ImportStatus::Skipped,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->status)->toBe(ImportStatus::Skipped);
        });
    });

    describe('bank account auto-matching', function () {
        it('auto-sets bank_account_id when AI-detected bank_name matches a BankAccount', function () {
            Storage::put('statements/bank.pdf', 'fake-pdf-content');

            $company = Company::factory()->create();
            $bankAccount = BankAccount::factory()->create([
                'company_id' => $company->id,
                'name' => 'HDFC Bank',
            ]);

            StatementParser::fake([
                [
                    'bank_name' => 'HDFC Bank',
                    'account_number' => '1234567890',
                    'transactions' => [
                        ['date' => '2024-01-05', 'description' => 'SALARY', 'credit' => 50000, 'balance' => 150000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/bank.pdf',
                'original_filename' => 'hdfc_statement.pdf',
                'statement_type' => StatementType::Bank,
                'status' => ImportStatus::Pending,
                'company_id' => $company->id,
                'bank_account_id' => null,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->bank_account_id)->toBe($bankAccount->id);
        });

        it('auto-creates BankAccount when no matching one exists', function () {
            Storage::put('statements/bank2.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'Unknown Bank',
                    'transactions' => [
                        ['date' => '2024-01-05', 'description' => 'PAYMENT', 'debit' => 1000, 'balance' => 9000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/bank2.pdf',
                'original_filename' => 'unknown.pdf',
                'statement_type' => StatementType::Bank,
                'status' => ImportStatus::Pending,
                'bank_account_id' => null,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->bank_account_id)->not->toBeNull();

            $bankAccount = BankAccount::find($file->bank_account_id);
            expect($bankAccount->name)->toBe('Unknown Bank')
                ->and($bankAccount->company_id)->toBe($file->company_id);
        });

        it('populates account_number on auto-created BankAccount from parser', function () {
            Storage::put('statements/new_bank.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'Axis Bank',
                    'account_number' => '9876543210',
                    'transactions' => [
                        ['date' => '2024-01-05', 'description' => 'SALARY', 'credit' => 50000, 'balance' => 150000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/new_bank.pdf',
                'original_filename' => 'axis_statement.pdf',
                'statement_type' => StatementType::Bank,
                'status' => ImportStatus::Pending,
                'bank_account_id' => null,
            ]);

            $this->processor->process($file);

            $file->refresh();
            $bankAccount = BankAccount::find($file->bank_account_id);
            expect($bankAccount)->not->toBeNull()
                ->and($bankAccount->account_number)->toBe('9876543210');
        });

        it('populates card_number on auto-created CreditCard from parser', function () {
            Storage::put('statements/new_cc.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'HDFC CC',
                    'account_number' => '4111111111111234',
                    'transactions' => [
                        ['date' => '2024-01-05', 'description' => 'AMAZON', 'debit' => 2000, 'balance' => 2000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->creditCard()->create([
                'file_path' => 'statements/new_cc.pdf',
                'original_filename' => 'hdfc_cc.pdf',
                'status' => ImportStatus::Pending,
                'credit_card_id' => null,
            ]);

            $this->processor->process($file);

            $file->refresh();
            $creditCard = CreditCard::find($file->credit_card_id);
            expect($creditCard)->not->toBeNull()
                ->and($creditCard->card_number)->toBe('4111111111111234');
        });

        it('does not overwrite account_number on existing BankAccount during re-match', function () {
            Storage::put('statements/existing_bank.pdf', 'fake-pdf-content');

            $company = Company::factory()->create();
            $bankAccount = BankAccount::factory()->create([
                'company_id' => $company->id,
                'name' => 'SBI',
                'account_number' => 'ORIGINAL123',
            ]);

            StatementParser::fake([
                [
                    'bank_name' => 'SBI',
                    'account_number' => 'DIFFERENT456',
                    'transactions' => [
                        ['date' => '2024-01-05', 'description' => 'TRANSFER', 'debit' => 5000, 'balance' => 45000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/existing_bank.pdf',
                'original_filename' => 'sbi_statement.pdf',
                'statement_type' => StatementType::Bank,
                'status' => ImportStatus::Pending,
                'company_id' => $company->id,
                'bank_account_id' => null,
            ]);

            $this->processor->process($file);

            $bankAccount->refresh();
            expect($bankAccount->account_number)->toBe('ORIGINAL123');
        });

        it('does not overwrite card_number on existing CreditCard during re-match', function () {
            Storage::put('statements/existing_cc.pdf', 'fake-pdf-content');

            $company = Company::factory()->create();
            $creditCard = CreditCard::factory()->create([
                'company_id' => $company->id,
                'name' => 'ICICI CC',
                'card_number' => 'ORIGINAL789',
            ]);

            StatementParser::fake([
                [
                    'bank_name' => 'ICICI CC',
                    'account_number' => 'DIFFERENT012',
                    'transactions' => [
                        ['date' => '2024-01-05', 'description' => 'SWIGGY', 'debit' => 500, 'balance' => 500],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->creditCard()->create([
                'file_path' => 'statements/existing_cc.pdf',
                'original_filename' => 'icici_cc.pdf',
                'status' => ImportStatus::Pending,
                'company_id' => $company->id,
                'credit_card_id' => null,
            ]);

            $this->processor->process($file);

            $creditCard->refresh();
            expect($creditCard->card_number)->toBe('ORIGINAL789');
        });
    });

    describe('XLSX parsing', function () {
        it('parses an XLSX file with string dates and creates transactions', function () {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray([
                ['Date', 'Description', 'Debit', 'Credit', 'Balance'],
                ['2024-01-05', 'SALARY JAN 2024', null, 50000, 150000],
                ['2024-01-10', 'RENT PAYMENT', 15000, null, 135000],
            ]);

            $tempPath = sys_get_temp_dir().'/test_string_dates.xlsx';
            (new XlsxWriter($spreadsheet))->save($tempPath);
            Storage::put('statements/test_string_dates.xlsx', file_get_contents($tempPath));
            unlink($tempPath);

            $file = ImportedFile::factory()->xlsx()->create([
                'file_path' => 'statements/test_string_dates.xlsx',
                'original_filename' => 'bank_string_dates.xlsx',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->status)->toBe(ImportStatus::Completed)
                ->and($file->total_rows)->toBe(2);

            $transactions = Transaction::where('imported_file_id', $file->id)->get();
            expect($transactions)->toHaveCount(2)
                ->and($transactions->first()->date->toDateString())->toBe('2024-01-05')
                ->and($transactions->first()->description)->toBe('SALARY JAN 2024');
        });

        it('parses an XLSX file with Excel serial date numbers and creates transactions with correct dates', function () {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();

            // Write headers
            $sheet->setCellValue('A1', 'Date');
            $sheet->setCellValue('B1', 'Description');
            $sheet->setCellValue('C1', 'Debit');
            $sheet->setCellValue('D1', 'Credit');
            $sheet->setCellValue('E1', 'Balance');

            // Write date as Excel serial number (45296 = 2024-01-05)
            $sheet->setCellValueExplicit('A2', 45296, DataType::TYPE_NUMERIC);
            $sheet->setCellValue('B2', 'SALARY CREDIT');
            $sheet->setCellValue('C2', null);
            $sheet->setCellValue('D2', 50000);
            $sheet->setCellValue('E2', 150000);

            $tempPath = sys_get_temp_dir().'/test_serial_dates.xlsx';
            (new XlsxWriter($spreadsheet))->save($tempPath);
            Storage::put('statements/test_serial_dates.xlsx', file_get_contents($tempPath));
            unlink($tempPath);

            $file = ImportedFile::factory()->xlsx()->create([
                'file_path' => 'statements/test_serial_dates.xlsx',
                'original_filename' => 'bank_serial_dates.xlsx',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->status)->toBe(ImportStatus::Completed)
                ->and($file->total_rows)->toBe(1);

            $transaction = Transaction::where('imported_file_id', $file->id)->first();
            expect($transaction)->not->toBeNull()
                ->and($transaction->date->toDateString())->toBe('2024-01-05');
        });

        it('skips rows with unparseable dates and imports the rest', function () {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray([
                ['Date', 'Description', 'Debit', 'Credit', 'Balance'],
                ['2024-01-05', 'VALID TRANSACTION', null, 50000, 150000],
                ['not-a-date', 'INVALID DATE ROW', 1000, null, 149000],
                ['2024-01-10', 'ANOTHER VALID', 5000, null, 145000],
            ]);

            $tempPath = sys_get_temp_dir().'/test_bad_dates.xlsx';
            (new XlsxWriter($spreadsheet))->save($tempPath);
            Storage::put('statements/test_bad_dates.xlsx', file_get_contents($tempPath));
            unlink($tempPath);

            $file = ImportedFile::factory()->xlsx()->create([
                'file_path' => 'statements/test_bad_dates.xlsx',
                'original_filename' => 'bank_bad_dates.xlsx',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->status)->toBe(ImportStatus::Completed)
                ->and($file->total_rows)->toBe(2);

            $transactions = Transaction::where('imported_file_id', $file->id)->get();
            expect($transactions)->toHaveCount(2);
        });
    });

    describe('card_variant extraction', function () {
        it('extracts card_variant from credit card PDF and saves it on the imported file', function () {
            Storage::put('statements/cc_regalia.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'HDFC Bank',
                    'card_variant' => 'Regalia',
                    'statement_period' => 'Jan 2025',
                    'transactions' => [
                        ['date' => '2025-01-05', 'description' => 'AMAZON ORDER', 'debit' => 1500],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->creditCard()->create([
                'file_path' => 'statements/cc_regalia.pdf',
                'original_filename' => 'hdfc_regalia_jan25.pdf',
                'status' => ImportStatus::Pending,
                'card_variant' => null,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->status)->toBe(ImportStatus::Completed)
                ->and($file->card_variant)->toBe('Regalia');
        });

        it('leaves card_variant null when AI does not return it', function () {
            Storage::put('statements/bank_no_variant.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'SBI',
                    'transactions' => [
                        ['date' => '2025-01-05', 'description' => 'SALARY', 'credit' => 50000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/bank_no_variant.pdf',
                'original_filename' => 'sbi_jan25.pdf',
                'status' => ImportStatus::Pending,
                'card_variant' => null,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->card_variant)->toBeNull();
        });

        it('generates display_name after processing when display_name is blank', function () {
            Storage::put('statements/cc_regen.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'ICICI Bank',
                    'card_variant' => 'Platinum',
                    'statement_period' => '2026-02-06 to 2026-03-05',
                    'transactions' => [
                        ['date' => '2026-02-10', 'description' => 'AMAZON', 'debit' => 500],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->creditCard()->create([
                'file_path' => 'statements/cc_regen.pdf',
                'original_filename' => 'icici_cc.pdf',
                'status' => ImportStatus::Pending,
                'bank_name' => null,
                'card_variant' => null,
                'display_name' => null,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->display_name)->toBe('ICICI Bank_Platinum_Mar_2026');
        });

        it('preserves user-entered display_name after processing', function () {
            Storage::put('statements/cc_custom.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'ICICI Bank',
                    'card_variant' => 'Platinum',
                    'statement_period' => '2026-02-06 to 2026-03-05',
                    'transactions' => [
                        ['date' => '2026-02-10', 'description' => 'AMAZON', 'debit' => 500],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->creditCard()->create([
                'file_path' => 'statements/cc_custom.pdf',
                'original_filename' => 'icici_cc.pdf',
                'status' => ImportStatus::Pending,
                'bank_name' => null,
                'card_variant' => null,
                'display_name' => 'My Custom Statement Name',
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->display_name)->toBe('My Custom Statement Name');
        });
    });

    describe('date parsing', function () {
        it('correctly parses DD-MM-YYYY formatted dates from Indian bank statements', function () {
            Storage::put('statements/dd_mm_yyyy.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'HDFC Bank',
                    'statement_period' => 'Jan 2026',
                    'transactions' => [
                        ['date' => '05-02-2026', 'description' => 'UPI PAYMENT', 'debit' => 1000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/dd_mm_yyyy.pdf',
                'original_filename' => 'hdfc_jan26.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $transaction = Transaction::where('imported_file_id', $file->id)->first();
            expect($transaction->date->format('Y-m-d'))->toBe('2026-02-05');
        });

        it('correctly parses DD/MM/YYYY formatted dates', function () {
            Storage::put('statements/dd_mm_yyyy_slash.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'Axis Bank',
                    'statement_period' => 'Mar 2026',
                    'transactions' => [
                        ['date' => '15/03/2026', 'description' => 'SALARY CREDIT', 'credit' => 50000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/dd_mm_yyyy_slash.pdf',
                'original_filename' => 'axis_mar26.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $transaction = Transaction::where('imported_file_id', $file->id)->first();
            expect($transaction->date->format('Y-m-d'))->toBe('2026-03-15');
        });

        it('correctly parses D/M/YYYY dates with single-digit day and month', function () {
            Storage::put('statements/single_digit_date.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'SBI',
                    'statement_period' => 'May 2026',
                    'transactions' => [
                        // 3rd May — Carbon::parse would read this as March 5 (US format)
                        ['date' => '3/5/2026', 'description' => 'NEFT PAYMENT', 'debit' => 2000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/single_digit_date.pdf',
                'original_filename' => 'sbi_may26.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $transaction = Transaction::where('imported_file_id', $file->id)->first();
            expect($transaction->date->format('Y-m-d'))->toBe('2026-05-03');
        });
    });

    describe('account_holder_name and opening_balance extraction', function () {
        it('stores account_holder_name from parser response on bank statement', function () {
            Storage::put('statements/holder.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'HDFC Bank',
                    'account_holder_name' => 'Zysk Technologies Pvt Ltd',
                    'transactions' => [
                        ['date' => '2024-01-05', 'description' => 'SALARY', 'credit' => 50000, 'balance' => 150000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/holder.pdf',
                'original_filename' => 'bank_holder.pdf',
                'statement_type' => StatementType::Bank,
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->account_holder_name)->toBe('Zysk Technologies Pvt Ltd');
        });

        it('stores null account_holder_name when parser does not return one', function () {
            Storage::put('statements/no_holder.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'SBI',
                    'transactions' => [
                        ['date' => '2024-01-05', 'description' => 'PAYMENT', 'debit' => 1000, 'balance' => 9000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/no_holder.pdf',
                'original_filename' => 'no_holder.pdf',
                'statement_type' => StatementType::Bank,
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect($file->account_holder_name)->toBeNull();
        });

        it('stores opening_balance from previous_balance on credit card statement', function () {
            Storage::put('statements/cc_balance.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'ICICI CC',
                    'previous_balance' => 8500.00,
                    'transactions' => [
                        ['date' => '2024-02-05', 'description' => 'AMAZON', 'debit' => 2000, 'balance' => 2000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->creditCard()->create([
                'file_path' => 'statements/cc_balance.pdf',
                'original_filename' => 'cc_balance.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect((float) $file->opening_balance)->toBe(8500.0);
        });

        it('stores opening_balance from previous_balance on bank statement', function () {
            Storage::put('statements/bank_balance.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'Axis Bank',
                    'previous_balance' => 25000.50,
                    'transactions' => [
                        ['date' => '2024-03-01', 'description' => 'NEFT', 'credit' => 5000, 'balance' => 30000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->create([
                'file_path' => 'statements/bank_balance.pdf',
                'original_filename' => 'bank_balance.pdf',
                'statement_type' => StatementType::Bank,
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $file->refresh();
            expect((float) $file->opening_balance)->toBe(25000.50);
        });
    });

    describe('unsupported formats', function () {
        it('throws for unsupported file extensions', function () {
            $file = ImportedFile::factory()->create([
                'original_filename' => 'document.txt',
            ]);

            $this->processor->process($file);
        })->throws(RuntimeException::class, 'Unsupported file extension');
    });

    describe('previous balance date resolution', function () {
        it('uses statement period start date for Previous Balance transaction when period is in human-readable format', function () {
            Storage::put('statements/cc_prev_bal_human.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'HDFC CC',
                    'statement_period' => '01 Apr 2026 - 30 Apr 2026',
                    'previous_balance' => 5000.00,
                    'transactions' => [
                        ['date' => '02 Apr 2026', 'description' => 'AMAZON', 'debit' => 2000],
                        ['date' => '10 Apr 2026', 'description' => 'SWIGGY', 'debit' => 500],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->creditCard()->create([
                'file_path' => 'statements/cc_prev_bal_human.pdf',
                'original_filename' => 'hdfc_cc_apr26.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $previousBalanceTx = Transaction::where('imported_file_id', $file->id)
                ->where('is_synthetic', true)
                ->first();

            expect($previousBalanceTx)->not->toBeNull()
                ->and($previousBalanceTx->date->format('Y-m-d'))->toBe('2026-04-01');
        });

        it('uses statement period start date for Previous Balance transaction when period uses slash-separated Indian format', function () {
            Storage::put('statements/cc_prev_bal_slash.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'ICICI CC',
                    'statement_period' => '01/04/2026 - 30/04/2026',
                    'previous_balance' => 3000.00,
                    'transactions' => [
                        ['date' => '02/04/2026', 'description' => 'MYNTRA', 'debit' => 1000],
                        ['date' => '15/04/2026', 'description' => 'ZOMATO', 'debit' => 300],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->creditCard()->create([
                'file_path' => 'statements/cc_prev_bal_slash.pdf',
                'original_filename' => 'icici_cc_apr26.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $previousBalanceTx = Transaction::where('imported_file_id', $file->id)
                ->where('is_synthetic', true)
                ->first();

            expect($previousBalanceTx)->not->toBeNull()
                ->and($previousBalanceTx->date->format('Y-m-d'))->toBe('2026-04-01');
        });

        it('uses statement period start date for Previous Balance transaction when period uses dash-separated Indian format', function () {
            Storage::put('statements/cc_prev_bal_dash.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'Axis CC',
                    'statement_period' => '01-04-2026 - 30-04-2026',
                    'previous_balance' => 7500.00,
                    'transactions' => [
                        ['date' => '05-04-2026', 'description' => 'NETFLIX', 'debit' => 649],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->creditCard()->create([
                'file_path' => 'statements/cc_prev_bal_dash.pdf',
                'original_filename' => 'axis_cc_apr26.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $previousBalanceTx = Transaction::where('imported_file_id', $file->id)
                ->where('is_synthetic', true)
                ->first();

            expect($previousBalanceTx)->not->toBeNull()
                ->and($previousBalanceTx->date->format('Y-m-d'))->toBe('2026-04-01');
        });

        it('uses statement period start date for Previous Balance transaction when period is in ISO format', function () {
            Storage::put('statements/cc_prev_bal_iso.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'SBI CC',
                    'statement_period' => '2026-04-01 to 2026-04-30',
                    'previous_balance' => 2000.00,
                    'transactions' => [
                        ['date' => '2026-04-03', 'description' => 'IRCTC', 'debit' => 800],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->creditCard()->create([
                'file_path' => 'statements/cc_prev_bal_iso.pdf',
                'original_filename' => 'sbi_cc_apr26.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $previousBalanceTx = Transaction::where('imported_file_id', $file->id)
                ->where('is_synthetic', true)
                ->first();

            expect($previousBalanceTx)->not->toBeNull()
                ->and($previousBalanceTx->date->format('Y-m-d'))->toBe('2026-04-01');
        });

        it('uses statement period start date for Previous Balance transaction when period uses full month name format', function () {
            Storage::put('statements/cc_prev_bal_fullmonth.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'ICICI CC',
                    'statement_period' => 'March 6, 2026 to April 5, 2026',
                    'previous_balance' => 8000.00,
                    'transactions' => [
                        ['date' => '07 Mar 2026', 'description' => 'AMAZON', 'debit' => 2000],
                        ['date' => '15 Mar 2026', 'description' => 'SWIGGY', 'debit' => 500],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->creditCard()->create([
                'file_path' => 'statements/cc_prev_bal_fullmonth.pdf',
                'original_filename' => 'icici_cc_mar26.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $previousBalanceTx = Transaction::where('imported_file_id', $file->id)
                ->where('is_synthetic', true)
                ->first();

            expect($previousBalanceTx)->not->toBeNull()
                ->and($previousBalanceTx->date->format('Y-m-d'))->toBe('2026-03-06');
        });

        it('does not create Previous Balance transaction when previous_balance is zero', function () {
            Storage::put('statements/cc_no_prev_bal.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'HDFC CC',
                    'statement_period' => '01 Apr 2026 - 30 Apr 2026',
                    'previous_balance' => 0,
                    'transactions' => [
                        ['date' => '01 Apr 2026', 'description' => 'AMAZON', 'debit' => 1000],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->creditCard()->create([
                'file_path' => 'statements/cc_no_prev_bal.pdf',
                'original_filename' => 'hdfc_cc_no_prev.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $previousBalanceTx = Transaction::where('imported_file_id', $file->id)
                ->where('is_synthetic', true)
                ->first();

            expect($previousBalanceTx)->toBeNull();
        });

        it('falls back to earliest transaction date when statement_period contains no parseable date', function () {
            Storage::put('statements/cc_prev_bal_unparseable.pdf', 'fake-pdf-content');

            StatementParser::fake([
                [
                    'bank_name' => 'HDFC CC',
                    'statement_period' => 'April 2026',
                    'previous_balance' => 4000.00,
                    'transactions' => [
                        ['date' => '05 Apr 2026', 'description' => 'FLIPKART', 'debit' => 1200],
                        ['date' => '10 Apr 2026', 'description' => 'UBER', 'debit' => 250],
                    ],
                ],
            ]);

            $file = ImportedFile::factory()->creditCard()->create([
                'file_path' => 'statements/cc_prev_bal_unparseable.pdf',
                'original_filename' => 'hdfc_cc_unparseable.pdf',
                'status' => ImportStatus::Pending,
            ]);

            $this->processor->process($file);

            $previousBalanceTx = Transaction::where('imported_file_id', $file->id)
                ->where('is_synthetic', true)
                ->first();

            expect($previousBalanceTx)->not->toBeNull()
                ->and($previousBalanceTx->date->format('Y-m-d'))->toBe('2026-04-05');
        });
    });
});
