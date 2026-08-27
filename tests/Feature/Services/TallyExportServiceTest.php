<?php

use App\Models\AccountHead;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Services\TallyExport\TallyExportService;

describe('TallyExportService', function () {
    beforeEach(function () {
        $this->company = Company::factory()->knownDefaults()->create();
        $this->bankAccount = BankAccount::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Icici Bank',
        ]);
        $this->file = ImportedFile::factory()->create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
            'bank_name' => 'ICICI',
        ]);
        $this->service = new TallyExportService;
    });

    describe('envelope structure', function () {
        it('generates valid XML with proper envelope structure', function () {
            $head = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'Salary Account',
            ]);
            Transaction::factory()->mapped($head)->debit(5000)->for($this->file)->create([
                'company_id' => $this->company->id,
                'description' => 'SALARY JUNE',
                'date' => '2024-06-15',
            ]);

            $xml = $this->service->exportForFile($this->file);

            expect($xml)->toContain('<?xml version="1.0" encoding="UTF-8"?>')
                ->and($xml)->toContain('<ENVELOPE>')
                ->and($xml)->toContain('<TALLYREQUEST>Import Data</TALLYREQUEST>')
                ->and($xml)->toContain('<IMPORTDATA>')
                ->and($xml)->toContain('<REQUESTDESC>')
                ->and($xml)->toContain('<REPORTNAME>Vouchers</REPORTNAME>')
                ->and($xml)->toContain('<REQUESTDATA>')
                ->and($xml)->toContain('</ENVELOPE>');
        });

        it('returns empty XML structure when no mapped transactions', function () {
            Transaction::factory()->unmapped()->for($this->file)->count(3)->create([
                'company_id' => $this->company->id,
            ]);

            $xml = $this->service->exportForFile($this->file);

            expect($xml)->toContain('<ENVELOPE>')
                ->and($xml)->toContain('<REQUESTDATA>')
                ->and($xml)->not->toContain('<TALLYMESSAGE');
        });
    });

    describe('debit journal vouchers', function () {
        it('generates journal voucher for debit transactions', function () {
            $head = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'TATA CAPITAL LIMITED',
            ]);
            Transaction::factory()->mapped($head)->debit(88609.51)->for($this->file)->create([
                'company_id' => $this->company->id,
                'description' => 'ACH/TATACAPFINSERLTD',
                'date' => '2025-04-01',
            ]);

            $xml = $this->service->exportForFile($this->file);

            expect($xml)->toContain('VCHTYPE="Journal"')
                ->and($xml)->toContain('ACTION="Create"')
                ->and($xml)->toContain('<DATE>20250401</DATE>')
                ->and($xml)->toContain('<VOUCHERTYPENAME>Journal</VOUCHERTYPENAME>')
                ->and($xml)->toContain('<NARRATION>ACH/TATACAPFINSERLTD</NARRATION>')
                ->and($xml)->toContain('<CMPGSTIN>27AABCA5012F1ZA</CMPGSTIN>')
                ->and($xml)->toContain('<CMPGSTREGISTRATIONTYPE>Regular</CMPGSTREGISTRATIONTYPE>')
                ->and($xml)->toContain('<CMPGSTSTATE>Maharashtra</CMPGSTSTATE>');
        });

        it('generates correct ledger entries for payment voucher', function () {
            $head = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'Rent Expense',
            ]);
            Transaction::factory()->mapped($head)->debit(50000.00)->for($this->file)->create([
                'company_id' => $this->company->id,
                'date' => '2025-04-15',
            ]);

            $xml = $this->service->exportForFile($this->file);

            // Debit leg: expense head, negative amount, ISDEEMEDPOSITIVE=Yes
            expect($xml)->toContain('<LEDGERNAME>Rent Expense</LEDGERNAME>')
                ->and($xml)->toContain('<ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>')
                ->and($xml)->toContain('<AMOUNT>-50000.00</AMOUNT>');

            // Credit leg: bank account, positive amount, ISDEEMEDPOSITIVE=No
            expect($xml)->toContain('<LEDGERNAME>Icici Bank</LEDGERNAME>')
                ->and($xml)->toContain('<ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>')
                ->and($xml)->toContain('<AMOUNT>50000.00</AMOUNT>');
        });

        it('includes BANKALLOCATIONS.LIST as empty list anchor in each ledger entry', function () {
            $head = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'Office Supplies',
            ]);
            Transaction::factory()->mapped($head)->debit(15000.00)->for($this->file)->create([
                'company_id' => $this->company->id,
                'date' => '2025-04-10',
            ]);

            $xml = $this->service->exportForFile($this->file);

            expect($xml)->toContain('<BANKALLOCATIONS.LIST>');
        });
    });

    describe('credit journal vouchers', function () {
        it('generates journal voucher for credit transactions', function () {
            $head = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'METAFIRST TECHNOLOGIES',
            ]);
            Transaction::factory()->mapped($head)->credit(150000.00)->for($this->file)->create([
                'company_id' => $this->company->id,
                'description' => 'Client Payment',
                'date' => '2025-04-20',
            ]);

            $xml = $this->service->exportForFile($this->file);

            expect($xml)->toContain('VCHTYPE="Journal"')
                ->and($xml)->toContain('<VOUCHERTYPENAME>Journal</VOUCHERTYPENAME>');
        });

        it('generates correct ledger entries for receipt voucher', function () {
            $head = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'Client Income',
            ]);
            Transaction::factory()->mapped($head)->credit(200000.00)->for($this->file)->create([
                'company_id' => $this->company->id,
                'date' => '2025-04-25',
            ]);

            $xml = $this->service->exportForFile($this->file);

            // Receipt: Bank is debit (negative, ISDEEMEDPOSITIVE=Yes)
            expect($xml)->toContain('<LEDGERNAME>Icici Bank</LEDGERNAME>')
                ->and($xml)->toContain('<AMOUNT>-200000.00</AMOUNT>');

            // Receipt: Party is credit (positive, ISDEEMEDPOSITIVE=No)
            expect($xml)->toContain('<LEDGERNAME>Client Income</LEDGERNAME>')
                ->and($xml)->toContain('<AMOUNT>200000.00</AMOUNT>');
        });
    });

    describe('voucher numbering', function () {
        it('generates sequential voucher numbers per voucher type', function () {
            $head1 = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'Expense A',
            ]);
            $head2 = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'Income B',
            ]);

            // Two payments
            Transaction::factory()->mapped($head1)->debit(1000)->for($this->file)->create([
                'company_id' => $this->company->id,
                'date' => '2025-04-01',
            ]);
            Transaction::factory()->mapped($head1)->debit(2000)->for($this->file)->create([
                'company_id' => $this->company->id,
                'date' => '2025-04-02',
            ]);
            // One receipt
            Transaction::factory()->mapped($head2)->credit(5000)->for($this->file)->create([
                'company_id' => $this->company->id,
                'date' => '2025-04-03',
            ]);

            $xml = $this->service->exportForFile($this->file);

            // Payment vouchers numbered 1, 2
            expect(substr_count($xml, '<VOUCHERNUMBER>1</VOUCHERNUMBER>'))->toBeGreaterThanOrEqual(1)
                ->and(substr_count($xml, '<VOUCHERNUMBER>2</VOUCHERNUMBER>'))->toBeGreaterThanOrEqual(1);
        });
    });

    describe('voucher balance', function () {
        it('generates balanced vouchers where amounts sum to zero', function () {
            $head = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'Test Expense',
            ]);
            Transaction::factory()->mapped($head)->debit(25000.50)->for($this->file)->create([
                'company_id' => $this->company->id,
                'date' => '2025-04-01',
            ]);

            $xml = $this->service->exportForFile($this->file);

            // Payment: debit leg = -25000.50, credit leg = 25000.50, sum = 0
            expect($xml)->toContain('<AMOUNT>-25000.50</AMOUNT>')
                ->and($xml)->toContain('<AMOUNT>25000.50</AMOUNT>');
        });
    });

    describe('XML escaping', function () {
        it('escapes special XML characters in descriptions and names', function () {
            $head = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'R&D Expenses',
            ]);
            Transaction::factory()->mapped($head)->debit(5000)->for($this->file)->create([
                'company_id' => $this->company->id,
                'description' => 'Payment for <Project Alpha> & "Beta"',
                'date' => '2025-04-01',
            ]);

            $xml = $this->service->exportForFile($this->file);

            expect($xml)->toContain('R&amp;D Expenses')
                ->and($xml)->toContain('&lt;Project Alpha&gt;')
                ->and($xml)->toContain('&amp; &quot;Beta&quot;');
        });
    });

    describe('exportForFile', function () {
        it('only exports mapped transactions', function () {
            $head = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'Mapped Head',
            ]);

            Transaction::factory()->mapped($head)->debit(5000)->for($this->file)->create([
                'company_id' => $this->company->id,
                'date' => '2025-04-01',
            ]);
            Transaction::factory()->unmapped()->for($this->file)->create([
                'company_id' => $this->company->id,
            ]);

            $xml = $this->service->exportForFile($this->file);

            expect(substr_count($xml, '<VOUCHER '))->toBe(1)
                ->and($xml)->toContain('Mapped Head');
        });
    });

    describe('exportTransactions', function () {
        it('exports a collection of transactions', function () {
            $head = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'Rent',
            ]);
            $transactions = Transaction::factory()
                ->mapped($head)
                ->debit(15000)
                ->for($this->file)
                ->count(2)
                ->create([
                    'company_id' => $this->company->id,
                    'date' => '2025-04-01',
                ]);

            $xml = $this->service->exportTransactions($transactions);

            expect($xml)->toContain('<ENVELOPE>')
                ->and($xml)->toContain('Rent')
                ->and(substr_count($xml, '<VOUCHER '))->toBe(2);
        });
    });

    describe('boilerplate fields', function () {
        it('includes standard boilerplate boolean flags', function () {
            $head = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'Expense',
            ]);
            Transaction::factory()->mapped($head)->debit(1000)->for($this->file)->create([
                'company_id' => $this->company->id,
                'date' => '2025-04-01',
            ]);

            $xml = $this->service->exportForFile($this->file);

            expect($xml)->toContain('<ISDELETED>No</ISDELETED>')
                ->and($xml)->toContain('<ISCANCELLED>No</ISCANCELLED>');
        });
    });

    describe('date formatting', function () {
        it('formats dates as YYYYMMDD without separators', function () {
            $head = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'Expense',
            ]);
            Transaction::factory()->mapped($head)->debit(1000)->for($this->file)->create([
                'company_id' => $this->company->id,
                'date' => '2025-12-31',
            ]);

            $xml = $this->service->exportForFile($this->file);

            expect($xml)->toContain('<DATE>20251231</DATE>')
                ->and($xml)->toContain('<EFFECTIVEDATE>20251231</EFFECTIVEDATE>');
        });
    });

    describe('valid XML output', function () {
        it('produces well-structured Tally XML envelope', function () {
            $head = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'Test Head',
            ]);
            Transaction::factory()->mapped($head)->debit(5000)->for($this->file)->create([
                'company_id' => $this->company->id,
                'date' => '2025-04-01',
            ]);

            $xml = $this->service->exportForFile($this->file);

            // Tally uses &#4; (U+0004 EOT) as a sentinel for "not applicable" enum fields.
            // This is forbidden in XML 1.0, so DOMDocument::loadXML() rejects the output.
            // We verify structural correctness via string matching instead.
            expect($xml)
                ->toStartWith('<?xml version="1.0" encoding="UTF-8"?>')
                ->toContain('<ENVELOPE>')
                ->toContain('</ENVELOPE>')
                ->toContain('<TALLYMESSAGE')
                ->toContain('<VOUCHER');
        });
    });

    describe('OBJVIEW attribute', function () {
        it('includes Accounting Voucher View on voucher element', function () {
            $head = AccountHead::factory()->create([
                'company_id' => $this->company->id,
                'name' => 'Expense',
            ]);
            Transaction::factory()->mapped($head)->debit(1000)->for($this->file)->create([
                'company_id' => $this->company->id,
                'date' => '2025-04-01',
            ]);

            $xml = $this->service->exportForFile($this->file);

            expect($xml)->toContain('OBJVIEW="Accounting Voucher View"');
        });
    });

    describe('configurable Tally ledger names', function () {
        beforeEach(function () {
            $this->invoiceFile = ImportedFile::factory()->invoice()->create([
                'company_id' => $this->company->id,
            ]);
        });

        it('falls back to default input IGST ledger name when not configured', function () {
            $head = AccountHead::factory()->create(['company_id' => $this->company->id, 'name' => 'Software Expense']);
            Transaction::factory()->mapped($head)->for($this->invoiceFile)->create([
                'company_id' => $this->company->id,
                'date' => '2025-04-01',
                'raw_data' => ['vendor_name' => 'Test Vendor', 'base_amount' => 10000, 'igst_rate' => 18, 'igst_amount' => 1800, 'total_amount' => 11800],
            ]);

            $xml = $this->service->exportForFile($this->invoiceFile);

            expect($xml)->toContain('Input Igst @ 18%');
        });

        it('uses custom input IGST ledger name when configured', function () {
            $this->company->update(['tally_input_igst_ledger' => 'IGST Input @{rate}%']);

            $head = AccountHead::factory()->create(['company_id' => $this->company->id, 'name' => 'Software Expense']);
            Transaction::factory()->mapped($head)->for($this->invoiceFile)->create([
                'company_id' => $this->company->id,
                'date' => '2025-04-01',
                'raw_data' => ['vendor_name' => 'Test Vendor', 'base_amount' => 10000, 'igst_rate' => 18, 'igst_amount' => 1800, 'total_amount' => 11800],
            ]);

            $xml = $this->service->exportForFile($this->invoiceFile);

            expect($xml)->toContain('IGST Input @18%')
                ->and($xml)->not->toContain('Input Igst @ 18%');
        });

        it('uses custom TDS payable ledger name when configured', function () {
            $this->company->update(['tally_tds_payable_ledger' => 'TDS Liability Account']);

            $head = AccountHead::factory()->create(['company_id' => $this->company->id, 'name' => 'Consulting Expense']);
            Transaction::factory()->mapped($head)->for($this->invoiceFile)->create([
                'company_id' => $this->company->id,
                'date' => '2025-04-01',
                'raw_data' => ['vendor_name' => 'Test Vendor', 'base_amount' => 10000, 'tds_amount' => 1000, 'total_amount' => 11000],
            ]);

            $xml = $this->service->exportForFile($this->invoiceFile);

            expect($xml)->toContain('TDS Liability Account')
                ->and($xml)->not->toContain('<LEDGERNAME>TDS Payable</LEDGERNAME>');
        });

        it('uses custom output IGST ledger name in sales vouchers', function () {
            $this->company->update(['tally_output_igst_ledger' => 'IGST Output Tax @{rate}%']);

            $head = AccountHead::factory()->create(['company_id' => $this->company->id, 'name' => 'Software Revenue']);
            Transaction::factory()->mapped($head)->for($this->invoiceFile)->create([
                'company_id' => $this->company->id,
                'date' => '2025-04-01',
                'raw_data' => ['buyer_name' => 'Client Corp', 'base_amount' => 50000, 'igst_rate' => 18, 'igst_amount' => 9000, 'total_amount' => 59000],
            ]);

            $xml = $this->service->exportForFile($this->invoiceFile);

            expect($xml)->toContain('IGST Output Tax @18%')
                ->and($xml)->not->toContain('Output Igst @ 18%');
        });
    });
});
