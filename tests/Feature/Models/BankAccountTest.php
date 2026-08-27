<?php

use App\Enums\AccountType;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\ImportedFile;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

describe('BankAccount factory', function () {
    it('creates a bank account with valid defaults', function () {
        $account = BankAccount::factory()->create();

        expect($account->exists)->toBeTrue()
            ->and($account->name)->toBeString()
            ->and($account->account_number)->toBeString()
            ->and($account->ifsc_code)->toBeString()
            ->and($account->account_type)->toBe(AccountType::Current)
            ->and($account->is_active)->toBeTrue();
    });

    it('creates a savings account', function () {
        $account = BankAccount::factory()->savings()->create();

        expect($account->account_type)->toBe(AccountType::Savings);
    });

    it('creates a credit card account', function () {
        $account = BankAccount::factory()->creditCard()->create();

        expect($account->account_type)->toBe(AccountType::CreditCard)
            ->and($account->ifsc_code)->toBeNull()
            ->and($account->branch)->toBeNull();
    });

    it('creates an inactive account', function () {
        $account = BankAccount::factory()->inactive()->create();

        expect($account->is_active)->toBeFalse();
    });
});

describe('BankAccount encryption', function () {
    it('encrypts account_number at rest', function () {
        $account = BankAccount::factory()->create(['account_number' => '1234567890123456']);

        // Reading through Eloquent should return decrypted value
        expect($account->account_number)->toBe('1234567890123456');

        // Raw DB value should be different (encrypted)
        $raw = DB::table('bank_accounts')
            ->where('id', $account->id)
            ->value('account_number');

        expect($raw)->not->toBe('1234567890123456');
    });
});

describe('BankAccount relationships', function () {
    it('belongs to a company', function () {
        $company = Company::factory()->create();
        $account = BankAccount::factory()->create(['company_id' => $company->id]);

        expect($account->company->id)->toBe($company->id);
    });

    it('has many imported files', function () {
        $account = BankAccount::factory()->create();
        ImportedFile::factory()->count(2)->create(['bank_account_id' => $account->id]);

        expect($account->importedFiles)->toHaveCount(2);
    });
});

describe('BankAccount soft deletes', function () {
    it('uses the SoftDeletes trait', function () {
        expect(in_array(SoftDeletes::class, class_uses_recursive(BankAccount::class)))->toBeTrue();
    });

    it('is excluded from normal queries after soft delete', function () {
        $account = BankAccount::factory()->create();
        $account->delete();

        expect(BankAccount::find($account->id))->toBeNull();
    });

    it('can be restored after soft delete', function () {
        $account = BankAccount::factory()->create();
        $account->delete();

        $account->restore();

        expect(BankAccount::find($account->id))->not->toBeNull();
    });

    it('is included in withTrashed queries after soft delete', function () {
        $account = BankAccount::factory()->create();
        $account->delete();

        expect(BankAccount::withTrashed()->find($account->id))->not->toBeNull()
            ->and(BankAccount::withTrashed()->find($account->id)->trashed())->toBeTrue();
    });
});

describe('BankAccount enum cast', function () {
    it('casts account_type to AccountType enum', function () {
        $account = BankAccount::factory()->create();

        expect($account->account_type)->toBeInstanceOf(AccountType::class);
    });

    it('casts is_active to boolean', function () {
        $account = BankAccount::factory()->create(['is_active' => 1]);

        expect($account->is_active)->toBeBool();
    });
});

describe('BankAccount activity log', function () {
    it('logs creation', function () {
        $account = BankAccount::factory()->create();

        expect($account->activities)->toHaveCount(1)
            ->and($account->activities->first()->description)->toBe('created');
    });

    it('logs updates', function () {
        $account = BankAccount::factory()->create();
        $account->update(['name' => 'Updated Bank']);

        $activities = $account->activities()->orderBy('id')->get();

        expect($activities)->toHaveCount(2)
            ->and($activities->last()->description)->toBe('updated');
    });
});
