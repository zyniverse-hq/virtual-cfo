<?php

use App\Enums\StatementType;
use App\Models\CreditCard;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Services\DisplayNameGenerator;
use Carbon\Carbon;

describe('DisplayNameGenerator', function () {
    it('generates bank name and period for bank account import with statement period', function () {
        $file = new ImportedFile;
        $file->forceFill([
            'bank_name' => 'HDFC',
            'statement_period' => 'Jan 2025',
            'statement_type' => StatementType::Bank,
            'created_at' => now(),
        ]);

        $name = (new DisplayNameGenerator)->generate($file);
        expect($name)->toBe('HDFC Jan 2025');
    });

    it('generates bank name, card type, and period for credit card import with statement period', function () {
        $card = new CreditCard;
        $card->forceFill(['name' => 'Regalia']);

        $file = new ImportedFile;
        $file->forceFill([
            'bank_name' => 'HDFC',
            'statement_period' => 'Jan 2025',
            'statement_type' => StatementType::CreditCard,
            'created_at' => now(),
        ]);
        $file->setRelation('creditCard', $card);

        $name = (new DisplayNameGenerator)->generate($file);
        expect($name)->toBe('HDFC Regalia Jan 2025');
    });

    it('falls back to created_at month/year when statement_period is null', function () {
        $file = new ImportedFile;
        $file->forceFill([
            'bank_name' => 'Axis',
            'statement_period' => null,
            'statement_type' => StatementType::Bank,
            'created_at' => Carbon::parse('2024-03-15'),
        ]);

        $name = (new DisplayNameGenerator)->generate($file);
        expect($name)->toBe('Axis Mar 2024');
    });

    it('includes card type from credit card name and falls back to created_at when no statement period', function () {
        $card = new CreditCard;
        $card->forceFill(['name' => 'Platinum']);

        $file = new ImportedFile;
        $file->forceFill([
            'bank_name' => 'ICICI',
            'statement_period' => null,
            'statement_type' => StatementType::CreditCard,
            'created_at' => Carbon::parse('2025-06-20'),
        ]);
        $file->setRelation('creditCard', $card);

        $name = (new DisplayNameGenerator)->generate($file);
        expect($name)->toBe('ICICI Platinum Jun 2025');
    });

    it('handles null bank_name gracefully without leading spaces', function () {
        $file = new ImportedFile;
        $file->forceFill([
            'bank_name' => null,
            'statement_period' => 'Feb 2025',
            'statement_type' => StatementType::Bank,
            'created_at' => now(),
        ]);

        $name = (new DisplayNameGenerator)->generate($file);
        expect($name)->toBe('Feb 2025');
    });

    it('uses card_variant in display name when set and no credit card relationship', function () {
        $file = new ImportedFile;
        $file->forceFill([
            'bank_name' => 'HDFC',
            'card_variant' => 'Regalia',
            'statement_period' => 'Jan 2025',
            'statement_type' => StatementType::Bank,
            'created_at' => now(),
        ]);

        $name = (new DisplayNameGenerator)->generate($file);
        expect($name)->toBe('HDFC Regalia Jan 2025');
    });

    it('extracts end month from YYYY-MM-DD range statement period', function () {
        $file = new ImportedFile;
        $file->forceFill([
            'bank_name' => 'ICICI Bank',
            'card_variant' => 'Platinum',
            'statement_period' => '2026-02-02 to 2026-03-01',
            'statement_type' => StatementType::Bank,
            'created_at' => now(),
        ]);

        $name = (new DisplayNameGenerator)->generate($file);
        expect($name)->toBe('ICICI Bank Platinum Mar 2026');
    });

    it('extracts end month from natural language range statement period', function () {
        $file = new ImportedFile;
        $file->forceFill([
            'bank_name' => 'ICICI Bank',
            'card_variant' => 'Ruby',
            'statement_period' => 'February 6, 2026 to March 5, 2026',
            'statement_type' => StatementType::Bank,
            'created_at' => now(),
        ]);

        $name = (new DisplayNameGenerator)->generate($file);
        expect($name)->toBe('ICICI Bank Ruby Mar 2026');
    });

    it('prefers card_variant over creditCard name when both are present', function () {
        $card = new CreditCard;
        $card->forceFill(['name' => 'Generic Card']);

        $file = new ImportedFile;
        $file->forceFill([
            'bank_name' => 'HDFC',
            'card_variant' => 'Millennia',
            'statement_period' => 'Feb 2025',
            'statement_type' => StatementType::CreditCard,
            'created_at' => now(),
        ]);
        $file->setRelation('creditCard', $card);

        $name = (new DisplayNameGenerator)->generate($file);
        expect($name)->toBe('HDFC Millennia Feb 2025');
    });

    it('generates invoice display name from invoice number, buyer name, and service description', function () {
        $file = new ImportedFile;
        $file->forceFill([
            'statement_type' => StatementType::Invoice,
        ]);

        $transaction = new Transaction;
        $transaction->forceFill([
            'raw_data' => [
                'invoice_number' => 'INV/2439',
                'buyer_name' => 'Test Vendor Pvt Ltd',
                'line_items' => [
                    ['description' => 'Office Assistant and Housekeeping charges', 'amount' => 27500.00],
                ],
            ],
        ]);

        $file->setRelation('transactions', collect([$transaction]));
        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('INV/2439 Test Vendor Office Assistant');
    });

    it('strips legal suffixes from buyer name in invoice display name', function () {
        $file = new ImportedFile;
        $file->forceFill([
            'statement_type' => StatementType::Invoice,
        ]);

        $transaction = new Transaction;
        $transaction->forceFill([
            'raw_data' => [
                'invoice_number' => 'ZY24-0045',
                'buyer_name' => 'Minds Creative Solutions Private Limited',
                'line_items' => [
                    ['description' => 'Website Development Project - Varuna Month - Jul\'24 to Aug\'24', 'amount' => 50000.00],
                ],
            ],
        ]);

        $file->setRelation('transactions', collect([$transaction]));
        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('ZY24-0045 Minds Creative Solutions Website Development');
    });

    it('generates invoice display name with only buyer name when invoice number and line items are missing', function () {
        $file = new ImportedFile;
        $file->forceFill([
            'statement_type' => StatementType::Invoice,
        ]);

        $transaction = new Transaction;
        $transaction->forceFill([
            'raw_data' => [
                'buyer_name' => 'Simple Vendor Ltd',
            ],
        ]);

        $file->setRelation('transactions', collect([$transaction]));
        $name = (new DisplayNameGenerator)->generate($file);

        expect($name)->toBe('Simple Vendor');
    });
});
