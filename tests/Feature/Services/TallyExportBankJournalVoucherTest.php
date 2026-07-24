<?php

use App\Models\AccountHead;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Services\TallyExport\TallyExportService;

describe('TallyExportService bank/CC journal vouchers', function () {
    beforeEach(function () {
        $this->company = Company::factory()->knownDefaults()->create();
        $this->bankAccount = BankAccount::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Amazon ICICI Credit Card',
        ]);
        $this->file = ImportedFile::factory()->create([
            'company_id' => $this->company->id,
            'bank_account_id' => $this->bankAccount->id,
        ]);
        $this->service = new TallyExportService;
    });

    it('exports bank/CC debit as Journal voucher not Payment', function () {
        $head = AccountHead::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Reliance Jio',
        ]);
        Transaction::factory()->mapped($head)->debit(299.00)->for($this->file)->create([
            'company_id' => $this->company->id,
            'description' => 'TOP UP DONE THRG AMAZON CARD',
            'date' => '2026-03-26',
        ]);

        $xml = $this->service->exportForFile($this->file);

        expect($xml)
            ->toContain('VCHTYPE="Journal"')
            ->toContain('<VOUCHERTYPENAME>Journal</VOUCHERTYPENAME>')
            ->not->toContain('VCHTYPE="Payment"')
            ->not->toContain('VCHTYPE="Receipt"');
    });

    it('exports bank/CC credit as Journal voucher not Receipt', function () {
        $head = AccountHead::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Client Income',
        ]);
        Transaction::factory()->mapped($head)->credit(50000.00)->for($this->file)->create([
            'company_id' => $this->company->id,
            'description' => 'Payment received',
            'date' => '2026-03-26',
        ]);

        $xml = $this->service->exportForFile($this->file);

        expect($xml)
            ->toContain('VCHTYPE="Journal"')
            ->toContain('<VOUCHERTYPENAME>Journal</VOUCHERTYPENAME>')
            ->not->toContain('VCHTYPE="Receipt"');
    });

    it('uses REPORTNAME Vouchers for bank/CC export', function () {
        $head = AccountHead::factory()->create(['company_id' => $this->company->id]);
        Transaction::factory()->mapped($head)->debit(299.00)->for($this->file)->create([
            'company_id' => $this->company->id,
            'date' => '2026-03-26',
        ]);

        $xml = $this->service->exportForFile($this->file);

        expect($xml)
            ->toContain('<REPORTNAME>Vouchers</REPORTNAME>')
            ->not->toContain('<REPORTNAME>All Masters</REPORTNAME>');
    });

    it('sets HASCASHFLOW to No on bank/CC journal voucher', function () {
        $head = AccountHead::factory()->create(['company_id' => $this->company->id]);
        Transaction::factory()->mapped($head)->debit(299.00)->for($this->file)->create([
            'company_id' => $this->company->id,
            'date' => '2026-03-26',
        ]);

        $xml = $this->service->exportForFile($this->file);

        expect($xml)->toContain('<HASCASHFLOW>No</HASCASHFLOW>');
    });

    it('sets ISPARTYLEDGER Yes on both ledger legs for a debit journal', function () {
        $head = AccountHead::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Reliance Jio',
        ]);
        Transaction::factory()->mapped($head)->debit(299.00)->for($this->file)->create([
            'company_id' => $this->company->id,
            'date' => '2026-03-26',
        ]);

        $xml = $this->service->exportForFile($this->file);

        expect(substr_count($xml, '<ISPARTYLEDGER>Yes</ISPARTYLEDGER>'))->toBe(2);
    });

    it('generates correct debit journal legs: expense head debit, bank/CC credit', function () {
        $head = AccountHead::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Reliance Jio',
        ]);
        Transaction::factory()->mapped($head)->debit(299.00)->for($this->file)->create([
            'company_id' => $this->company->id,
            'date' => '2026-03-26',
        ]);

        $xml = $this->service->exportForFile($this->file);

        expect($xml)
            ->toContain('<LEDGERNAME>Reliance Jio</LEDGERNAME>')
            ->toContain('<ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>')
            ->toContain('<AMOUNT>-299.00</AMOUNT>')
            ->toContain('<LEDGERNAME>Amazon ICICI Credit Card</LEDGERNAME>')
            ->toContain('<ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>')
            ->toContain('<AMOUNT>299.00</AMOUNT>');
    });

    it('generates correct credit journal legs: bank/CC debit, income head credit', function () {
        $head = AccountHead::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Client Income',
        ]);
        Transaction::factory()->mapped($head)->credit(50000.00)->for($this->file)->create([
            'company_id' => $this->company->id,
            'date' => '2026-03-26',
        ]);

        $xml = $this->service->exportForFile($this->file);

        expect($xml)
            ->toContain('<LEDGERNAME>Amazon ICICI Credit Card</LEDGERNAME>')
            ->toContain('<ISDEEMEDPOSITIVE>Yes</ISDEEMEDPOSITIVE>')
            ->toContain('<AMOUNT>-50000.00</AMOUNT>')
            ->toContain('<LEDGERNAME>Client Income</LEDGERNAME>')
            ->toContain('<ISDEEMEDPOSITIVE>No</ISDEEMEDPOSITIVE>')
            ->toContain('<AMOUNT>50000.00</AMOUNT>');
    });

    it('includes BANKALLOCATIONS.LIST as empty list anchor inside each ledger entry', function () {
        $head = AccountHead::factory()->create(['company_id' => $this->company->id]);
        Transaction::factory()->mapped($head)->debit(299.00)->for($this->file)->create([
            'company_id' => $this->company->id,
            'date' => '2026-03-26',
        ]);

        $xml = $this->service->exportForFile($this->file);

        expect($xml)->toContain('<BANKALLOCATIONS.LIST>');
    });
});

describe('TallyExportService bank/CC ledger name fallback', function () {
    beforeEach(function () {
        $this->company = Company::factory()->knownDefaults()->create();
        $this->service = new TallyExportService;
    });

    it('does not use display_name as the bank/CC ledger name when bank_account is null', function () {
        $file = ImportedFile::factory()->create([
            'company_id' => $this->company->id,
            'bank_account_id' => null,
            'display_name' => 'ICICI Platinum April 2026',
        ]);
        $head = AccountHead::factory()->create(['company_id' => $this->company->id, 'name' => 'Internet Expense']);
        Transaction::factory()->mapped($head)->debit(299.00)->for($file)->create([
            'company_id' => $this->company->id,
            'date' => '2026-03-26',
        ]);

        $xml = $this->service->exportForFile($file);

        expect($xml)->not->toContain('<LEDGERNAME>ICICI Platinum April 2026</LEDGERNAME>');
    });

    it('falls back to Bank Account sentinel when bank_account and bank_name are both null', function () {
        $file = ImportedFile::factory()->create([
            'company_id' => $this->company->id,
            'bank_account_id' => null,
            'display_name' => 'ICICI Platinum April 2026',
        ]);
        $head = AccountHead::factory()->create(['company_id' => $this->company->id, 'name' => 'Internet Expense']);
        Transaction::factory()->mapped($head)->debit(299.00)->for($file)->create([
            'company_id' => $this->company->id,
            'date' => '2026-03-26',
        ]);

        $xml = $this->service->exportForFile($file);

        expect($xml)->toContain('<LEDGERNAME>Bank Account</LEDGERNAME>');
    });

    it('uses bankAccount name when set, ignoring display_name', function () {
        $bankAccount = BankAccount::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'ICICI Platinum',
        ]);
        $file = ImportedFile::factory()->create([
            'company_id' => $this->company->id,
            'bank_account_id' => $bankAccount->id,
            'display_name' => 'ICICI Platinum April 2026',
        ]);
        $head = AccountHead::factory()->create(['company_id' => $this->company->id, 'name' => 'Internet Expense']);
        Transaction::factory()->mapped($head)->debit(299.00)->for($file)->create([
            'company_id' => $this->company->id,
            'date' => '2026-03-26',
        ]);

        $xml = $this->service->exportForFile($file);

        expect($xml)
            ->toContain('<LEDGERNAME>ICICI Platinum</LEDGERNAME>')
            ->not->toContain('<LEDGERNAME>ICICI Platinum April 2026</LEDGERNAME>');
    });
});
