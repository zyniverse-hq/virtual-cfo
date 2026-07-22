<?php

use App\Models\AccountHead;
use App\Models\Transaction;
use App\Models\TransactionAggregate;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

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

    it('throws exception when deleting if transactions are mapped', function () {
        $head = AccountHead::factory()->create();
        Transaction::factory()->create(['account_head_id' => $head->id]);

        expect(fn () => $head->delete())
            ->toThrow(ValidationException::class);
    });

    it('throws exception when force deleting if transactions are mapped', function () {
        $head = AccountHead::factory()->create();
        Transaction::factory()->create(['account_head_id' => $head->id]);

        expect(fn () => $head->forceDelete())
            ->toThrow(ValidationException::class);
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

describe('AccountHead deletion guard (replaces soft-delete cascade tests)', function () {
    beforeEach(function () {
        asUser();
    });

    it('preserves account_head_id on mapped transactions when delete is blocked', function () {
        $head = AccountHead::factory()->create();
        $txn = Transaction::factory()->mapped($head)->create();

        try {
            $head->delete();
        } catch (ValidationException) {
            // expected
        }

        expect($txn->fresh()->account_head_id)->toBe($head->id);
    });

    it('does not alter TransactionAggregate when delete is blocked', function () {
        $company = tenant();
        $head = AccountHead::factory()->create(['company_id' => $company->id]);

        Transaction::factory()->mapped($head)->debit(5000)->create([
            'company_id' => $company->id,
            'date' => '2025-04-15',
        ]);

        $aggregateBefore = TransactionAggregate::where('company_id', $company->id)
            ->where('account_head_id', $head->id)
            ->exists();

        try {
            $head->delete();
        } catch (ValidationException) {
            // expected
        }

        $aggregateAfter = TransactionAggregate::where('company_id', $company->id)
            ->where('account_head_id', $head->id)
            ->exists();

        expect($aggregateBefore)->toBe($aggregateAfter);
    });

    it('allows deletion when no transactions are mapped', function () {
        $head = AccountHead::factory()->create();

        $head->delete();

        expect(AccountHead::find($head->id))->toBeNull();
        expect(AccountHead::withTrashed()->find($head->id))->not->toBeNull();
    });

    it('includes the transaction count in the error message', function () {
        $head = AccountHead::factory()->create();
        Transaction::factory()->mapped($head)->count(3)->create();

        try {
            $head->delete();
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            expect($e->errors()['base'][0])->toContain('3 transactions are');
        }
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
