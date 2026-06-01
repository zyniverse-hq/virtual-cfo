<?php

use App\Enums\StatementType;
use App\Models\Company;
use App\Models\CreditCard;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Services\DisplayNameGenerator;
use Carbon\Carbon;

describe('DisplayNameGenerator', function () {
    it('generates bank name and period for bank account import with statement period', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => 'HDFC',
            'statement_period' => 'Jan 2025',
            'credit_card_id' => null,
        ]);

        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('HDFC_Jan_2025');
    });

    it('generates bank name, card type, and period for credit card import with statement period', function () {
        $card = CreditCard::factory()->create([
            'company_id' => $this->tenant?->id ?? Company::factory()->create()->id,
            'name' => 'Regalia',
        ]);

        $file = ImportedFile::factory()->create([
            'bank_name' => 'HDFC',
            'credit_card_id' => $card->id,
            'statement_period' => 'Jan 2025',
        ]);

        $file->load('creditCard');

        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('HDFC_Regalia_Jan_2025');
    });

    it('falls back to created_at month/year when statement_period is null', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => 'Axis',
            'statement_period' => null,
            'credit_card_id' => null,
            'created_at' => Carbon::parse('2024-03-15'),
        ]);

        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('Axis_Mar_2024');
    });

    it('includes card type from credit card name and falls back to created_at when no statement period', function () {
        $card = CreditCard::factory()->create([
            'name' => 'Platinum',
        ]);

        $file = ImportedFile::factory()->create([
            'bank_name' => 'ICICI',
            'credit_card_id' => $card->id,
            'statement_period' => null,
            'created_at' => Carbon::parse('2025-06-20'),
        ]);

        $file->load('creditCard');

        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('ICICI_Platinum_Jun_2025');
    });

    it('handles null bank_name gracefully', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => null,
            'statement_period' => 'Feb 2025',
            'credit_card_id' => null,
        ]);

        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('_Feb_2025');
    });

    it('uses user-supplied display_name as-is when provided', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => 'HDFC',
            'statement_period' => 'Jan 2025',
            'display_name' => 'My Custom Name',
        ]);

        expect($file->display_name)->toBe('My Custom Name');
    });

    it('uses card_variant in display name when set and no credit card relationship', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => 'HDFC',
            'card_variant' => 'Regalia',
            'statement_period' => 'Jan 2025',
            'credit_card_id' => null,
        ]);

        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('HDFC_Regalia_Jan_2025');
    });

    it('extracts end month from YYYY-MM-DD range statement period', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => 'ICICI Bank',
            'card_variant' => 'Platinum',
            'statement_period' => '2026-02-02 to 2026-03-01',
            'credit_card_id' => null,
        ]);

        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('ICICI Bank_Platinum_Mar_2026');
    });

    it('extracts end month from natural language range statement period', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => 'ICICI Bank',
            'card_variant' => 'Ruby',
            'statement_period' => 'February 6, 2026 to March 5, 2026',
            'credit_card_id' => null,
        ]);

        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('ICICI Bank_Ruby_Mar_2026');
    });

    it('prefers card_variant over creditCard name when both are present', function () {
        $card = CreditCard::factory()->create([
            'company_id' => $this->tenant?->id ?? Company::factory()->create()->id,
            'name' => 'Generic Card',
        ]);

        $file = ImportedFile::factory()->create([
            'bank_name' => 'HDFC',
            'credit_card_id' => $card->id,
            'card_variant' => 'Millennia',
            'statement_period' => 'Feb 2025',
        ]);

        $file->load('creditCard');

        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('HDFC_Millennia_Feb_2025');
    });

    it('generates invoice display name as party name, month, then invoice number', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
        ]);
        Transaction::factory()->create([
            'imported_file_id' => $file->id,
            'date' => Carbon::parse('2026-04-15'),
            'raw_data' => [
                'invoice_number' => 'INV/2439',
                'buyer_name' => 'Test Vendor Pvt Ltd',
            ],
        ]);

        $file->load('transactions');
        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('Test Vendor_Apr_2026_INV/2439');
    });

    it('strips legal suffixes from party name in invoice display name', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
        ]);
        Transaction::factory()->create([
            'imported_file_id' => $file->id,
            'date' => Carbon::parse('2024-07-01'),
            'raw_data' => [
                'invoice_number' => 'ZY24-0045',
                'buyer_name' => 'Minds Creative Solutions Private Limited',
            ],
        ]);

        $file->load('transactions');
        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('Minds Creative Solutions_Jul_2024_ZY24-0045');
    });

    it('prefers vendor_name over buyer_name for purchase invoices', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
        ]);
        Transaction::factory()->create([
            'imported_file_id' => $file->id,
            'date' => Carbon::parse('2026-03-20'),
            'raw_data' => [
                'invoice_number' => 'INV/0099',
                'vendor_name' => 'Reliance Jio Infocomm Limited',
                'buyer_name' => 'Zysk Technologies',
            ],
        ]);

        $file->load('transactions');
        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('Reliance Jio Infocomm_Mar_2026_INV/0099');
    });

    it('omits invoice number segment when invoice_number is absent', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
        ]);
        Transaction::factory()->create([
            'imported_file_id' => $file->id,
            'date' => Carbon::parse('2025-09-05'),
            'raw_data' => [
                'vendor_name' => 'Simple Vendor Ltd',
            ],
        ]);

        $file->load('transactions');
        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('Simple Vendor_Sep_2025');
    });

    it('falls back to Invoice_month when no party name is available', function () {
        $file = ImportedFile::factory()->create([
            'statement_type' => StatementType::Invoice,
            'created_at' => Carbon::parse('2025-11-10'),
        ]);
        Transaction::factory()->create([
            'imported_file_id' => $file->id,
            'date' => Carbon::parse('2025-11-01'),
            'raw_data' => [
                'invoice_number' => 'INV/0001',
            ],
        ]);

        $file->load('transactions');
        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('Invoice_Nov_2025_INV/0001');
    });
});
