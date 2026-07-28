<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StatementType: string implements HasLabel
{
    case Bank = 'bank';
    case CreditCard = 'credit_card';
    case Invoice = 'invoice';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bank => 'Bank Statement',
            self::CreditCard => 'Credit Card Statement',
            self::Invoice => 'Invoice',
        };
    }

    /**
     * Whether debits increase the running balance for this statement type.
     *
     * Credit card statements accrue with debits (charges) and reduce with
     * credits (payments/refunds); bank statements are the reverse.
     */
    public function closingBalanceAddsDebit(): bool
    {
        return match ($this) {
            self::CreditCard => true,
            self::Bank, self::Invoice => false,
        };
    }
}
