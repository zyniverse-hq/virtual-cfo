<?php

use App\Models\AccountHead;
use App\Models\Transaction;
use App\Models\TransactionAggregate;
use Illuminate\Database\Eloquent\SoftDeletes;

describe('AccountHead soft deletes', function () {
    it('uses the SoftDeletes trait', function () {
        expect(in_array(SoftDeletes::class, class_uses_recursive(AccountHead::class)))->toBeTrue();
    });

    it('is excluded from normal queries after soft delete', function () {
        $head = AccountHead::factory()->create();

        $head->delete();

        expect(AccountHead::find($head->id))->toBeNull();
    });

    it('can be restored after soft delete', function () {
        $head = AccountHead::factory()->create();
        $head->delete();

        $head->restore();

        expect(AccountHead::find($head->id))->not->toBeNull();
    });

    it('is permanently removed after force delete', function () {
        $head = AccountHead::factory()->create();

        $head->forceDelete();

        expect(AccountHead::withTrashed()->find($head->id))->toBeNull();
    });

    it('is included in withTrashed queries after soft delete', function () {
        $head = AccountHead::factory()->create();
        $head->delete();

        expect(AccountHead::withTrashed()->find($head->id))->not->toBeNull();
        expect(AccountHead::withTrashed()->find($head->id)->trashed())->toBeTrue();
    });

    it('allows duplicate name+group when one is soft-deleted', function () {
        $head = AccountHead::factory()->create([
            'name' => 'Unique Test Head',
            'group_name' => 'Test Group',
        ]);
        $head->delete();

        $newHead = AccountHead::factory()->create([
            'name' => 'Unique Test Head',
            'group_name' => 'Test Group',
        ]);

        expect($newHead->exists)->toBeTrue();
    });
});

describe('AccountHead::fullPath', function () {
    it('returns just the name when there is no parent', function () {
        $head = AccountHead::factory()->create(['name' => 'Bank Accounts']);

        expect($head->full_path)->toBe('Bank Accounts');
    });

    it('builds hierarchical path with one parent', function () {
        $parent = AccountHead::factory()->create(['name' => 'Current Assets']);
        $child = AccountHead::factory()->withParent($parent)->create(['name' => 'Bank Accounts']);

        expect($child->full_path)->toBe('Current Assets > Bank Accounts');
    });

    it('builds hierarchical path with multiple levels', function () {
        $grandparent = AccountHead::factory()->create(['name' => 'Assets']);
        $parent = AccountHead::factory()->withParent($grandparent)->create(['name' => 'Current Assets']);
        $child = AccountHead::factory()->withParent($parent)->create(['name' => 'Bank Accounts']);

        expect($child->full_path)->toBe('Assets > Current Assets > Bank Accounts');
    });
});

describe('AccountHead soft-delete cascade', function () {
    beforeEach(function () {
        asUser();
    });

    it('nulls account_head_id on mapped transactions when soft-deleted', function () {
        $head = AccountHead::factory()->create();
        $txn = Transaction::factory()->mapped($head)->create();

        $head->delete();

        expect($txn->fresh()->account_head_id)->toBeNull();
    });

    it('updates TransactionAggregate when soft-deleting an account head', function () {
        $company = tenant();
        $head = AccountHead::factory()->create(['company_id' => $company->id]);

        Transaction::factory()->mapped($head)->debit(5000)->create([
            'company_id' => $company->id,
            'date' => '2025-04-15',
        ]);

        // Aggregate should have the head assigned
        expect(TransactionAggregate::where('company_id', $company->id)
            ->where('account_head_id', $head->id)
            ->exists()
        )->toBeTrue();

        $head->delete();

        // Aggregate should now be under null head
        expect(TransactionAggregate::where('company_id', $company->id)
            ->where('account_head_id', $head->id)
            ->exists()
        )->toBeFalse()
            ->and(TransactionAggregate::where('company_id', $company->id)
                ->whereNull('account_head_id')
                ->exists()
            )->toBeTrue();
    });

    it('does not re-map transactions when an account head is restored', function () {
        $head = AccountHead::factory()->create();
        $txn = Transaction::factory()->mapped($head)->create();

        $head->delete();

        // After soft-delete: transaction is unmapped
        expect($txn->fresh()->account_head_id)->toBeNull();

        $head->restore();

        // After restore: transaction remains unmapped
        expect($txn->fresh()->account_head_id)->toBeNull();
    });

    it('does not null transactions when force-deleted (DB cascade handles it)', function () {
        $head = AccountHead::factory()->create();
        $txn = Transaction::factory()->mapped($head)->create();

        $head->forceDelete();

        // DB nullOnDelete fires — transaction's account_head_id is nulled by PostgreSQL
        expect($txn->fresh()->account_head_id)->toBeNull();
    });
});

describe('AccountHead name normalization', function () {
    it('trims leading and trailing whitespace from name on create', function () {
        $head = AccountHead::factory()->create(['name' => '  Internet Expense  ']);

        expect($head->fresh()->name)->toBe('Internet Expense');
    });

    it('strips trailing newline from name on create', function () {
        $head = AccountHead::factory()->create(['name' => "AMAZON WEB SERVICES INDIA PRIVATE LIMITED\n"]);

        expect($head->fresh()->name)->toBe('AMAZON WEB SERVICES INDIA PRIVATE LIMITED');
    });

    it('collapses double internal spaces to single space on create', function () {
        $head = AccountHead::factory()->create(['name' => 'Godaddy India  - Subscription']);

        expect($head->fresh()->name)->toBe('Godaddy India - Subscription');
    });

    it('normalizes name with mixed whitespace artifacts', function () {
        $head = AccountHead::factory()->create(['name' => "  AWS  India  \n"]);

        expect($head->fresh()->name)->toBe('AWS India');
    });

    it('leaves already-clean names unchanged', function () {
        $head = AccountHead::factory()->create(['name' => 'Internet Expense']);

        expect($head->fresh()->name)->toBe('Internet Expense');
    });

    it('normalizes name on update too', function () {
        $head = AccountHead::factory()->create(['name' => 'Internet Expense']);

        $head->update(['name' => "Telecom Expense  \n"]);

        expect($head->fresh()->name)->toBe('Telecom Expense');
    });
});

describe('AccountHead relationships', function () {
    it('has children', function () {
        $parent = AccountHead::factory()->create();
        $children = AccountHead::factory()->withParent($parent)->count(3)->create();

        expect($parent->children)->toHaveCount(3);
    });

    it('has transactions', function () {
        $head = AccountHead::factory()->create();

        expect($head->transactions)->toBeEmpty();
    });

    it('has head mappings', function () {
        $head = AccountHead::factory()->create();

        expect($head->headMappings)->toBeEmpty();
    });
});
