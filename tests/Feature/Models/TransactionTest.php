<?php

use App\Models\AccountHead;
use App\Models\Company;
use App\Models\ImportedFile;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\SoftDeletes;

describe('Transaction soft deletes', function () {
    it('uses the SoftDeletes trait', function () {
        expect(in_array(SoftDeletes::class, class_uses_recursive(Transaction::class)))->toBeTrue();
    });

    it('is excluded from normal queries after soft delete', function () {
        $transaction = Transaction::factory()->create();

        $transaction->delete();

        expect(Transaction::find($transaction->id))->toBeNull();
    });

    it('can be restored after soft delete', function () {
        $transaction = Transaction::factory()->create();
        $transaction->delete();

        $transaction->restore();

        expect(Transaction::find($transaction->id))->not->toBeNull();
    });

    it('is permanently removed after force delete', function () {
        $transaction = Transaction::factory()->create();

        $transaction->forceDelete();

        expect(Transaction::withTrashed()->find($transaction->id))->toBeNull();
    });
});

describe('Transaction scopes', function () {
    beforeEach(function () {
        $this->file = ImportedFile::factory()->create();
        $this->head = AccountHead::factory()->create();
    });

    it('filters unmapped transactions', function () {
        Transaction::factory()->unmapped()->for($this->file)->count(3)->create();
        Transaction::factory()->mapped($this->head)->for($this->file)->count(2)->create();

        expect(Transaction::unmapped()->count())->toBe(3);
    });

    it('filters mapped transactions', function () {
        Transaction::factory()->unmapped()->for($this->file)->count(3)->create();
        Transaction::factory()->mapped($this->head)->for($this->file)->count(2)->create();

        expect(Transaction::mapped()->count())->toBe(2);
    });

    it('filters transactions needing review', function () {
        Transaction::factory()->aiMapped($this->head, confidence: 0.5)->for($this->file)->create();
        Transaction::factory()->aiMapped($this->head, confidence: 0.9)->for($this->file)->create();
        Transaction::factory()->unmapped()->for($this->file)->create();

        expect(Transaction::needsReview()->count())->toBe(1);
    });
});

describe('Transaction accessors', function () {
    it('decrypts debit value', function () {
        $transaction = Transaction::factory()->debit(5000.50)->create();

        $fresh = Transaction::find($transaction->id);
        expect($fresh->decrypted_debit)->toBe(5000.50);
    });

    it('returns null for debit when not set', function () {
        $transaction = Transaction::factory()->credit(1000)->create();

        $fresh = Transaction::find($transaction->id);
        expect($fresh->decrypted_debit)->toBeNull();
    });

    it('decrypts credit value', function () {
        $transaction = Transaction::factory()->credit(10000.75)->create();

        $fresh = Transaction::find($transaction->id);
        expect($fresh->decrypted_credit)->toBe(10000.75);
    });

    it('decrypts balance value', function () {
        $transaction = Transaction::factory()->create(['balance' => 50000.25]);

        $fresh = Transaction::find($transaction->id);
        expect($fresh->decrypted_balance)->toBe(50000.25);
    });
});

describe('Transaction relationships', function () {
    it('belongs to an imported file', function () {
        $transaction = Transaction::factory()->create();

        expect($transaction->importedFile)->not->toBeNull();
    });

    it('belongs to an account head when mapped', function () {
        $head = AccountHead::factory()->create();
        $transaction = Transaction::factory()->mapped($head)->create();

        expect($transaction->accountHead->id)->toBe($head->id);
    });

    it('has no account head when unmapped', function () {
        $transaction = Transaction::factory()->unmapped()->create();

        expect($transaction->accountHead)->toBeNull();
    });
});

describe('Transaction encryption', function () {
    it('encrypts and decrypts description', function () {
        $transaction = Transaction::factory()->create(['description' => 'NEFT-123456-Test Company']);

        $fresh = Transaction::find($transaction->id);
        expect($fresh->description)->toBe('NEFT-123456-Test Company');
    });
});

describe('Transaction currency', function () {
    it('fills a missing currency from the company when creating', function () {
        $company = Company::factory()->create(['currency' => 'USD']);
        $transaction = Transaction::factory()->for($company)->create(['currency' => null]);
        expect($transaction->fresh()->currency)->toBe('USD');
    });

    it('keeps an explicitly set currency when creating', function () {
        $company = Company::factory()->create(['currency' => 'USD']);
        $transaction = Transaction::factory()->for($company)->create(['currency' => 'EUR']);
        expect($transaction->fresh()->currency)->toBe('EUR');
    });

    it('falls back to the default currency when the company cannot be found', function () {
        expect(Transaction::currencyForCompany(null))->toBe('INR')
            ->and(Transaction::currencyForCompany(999999))->toBe('INR');
    });

    it('does not overwrite an existing currency when moved to another company', function () {
        $target = Company::factory()->create(['currency' => 'INR']);
        $transaction = Transaction::factory()->create(['currency' => 'USD']);
        $transaction->moveToCompany($target);
        expect($transaction->fresh()->currency)->toBe('USD')
            ->and($transaction->fresh()->company_id)->toBe($target->id);
    });

    it('fills a missing currency from the target company when moved', function () {
        $target = Company::factory()->create(['currency' => 'GBP']);
        $transaction = Transaction::factory()->create();
        $transaction->forceFill(['currency' => null])->saveQuietly();
        $transaction->moveToCompany($target);
        expect($transaction->fresh()->currency)->toBe('GBP');
    });
});
