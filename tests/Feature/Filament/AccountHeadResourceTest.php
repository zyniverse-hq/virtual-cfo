<?php

use App\Filament\Resources\AccountHeadResource;
use App\Filament\Resources\AccountHeadResource\Pages\CreateAccountHead;
use App\Filament\Resources\AccountHeadResource\Pages\EditAccountHead;
use App\Filament\Resources\AccountHeadResource\Pages\ListAccountHeads;
use App\Models\AccountHead;
use App\Models\Transaction;

use function Pest\Livewire\livewire;

describe('AccountHeadResource', function () {
    beforeEach(function () {
        asUser();
    });

    it('can render the list page', function () {
        livewire(ListAccountHeads::class)->assertSuccessful();
    });

    it('can list account heads', function () {
        $heads = AccountHead::factory()->count(3)->create();

        livewire(ListAccountHeads::class)
            ->assertCanSeeTableRecords($heads);
    });

    it('can render the create page', function () {
        livewire(CreateAccountHead::class)->assertSuccessful();
    });

    it('can create an account head', function () {
        livewire(CreateAccountHead::class)
            ->fillForm([
                'name' => 'Test Account Head',
                'group_name' => 'Current Assets',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(AccountHead::where('name', 'Test Account Head')->exists())->toBeTrue();
    });

    it('validates required fields on create', function () {
        livewire(CreateAccountHead::class)
            ->fillForm([
                'name' => '',
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);
    });

    it('can render the edit page', function () {
        $head = AccountHead::factory()->create();

        livewire(EditAccountHead::class, ['record' => $head->getRouteKey()])
            ->assertSuccessful();
    });

    it('can update an account head', function () {
        $head = AccountHead::factory()->create(['name' => 'Old Name']);

        livewire(EditAccountHead::class, ['record' => $head->getRouteKey()])
            ->fillForm([
                'name' => 'Updated Name',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($head->fresh()->name)->toBe('Updated Name');
    });

    it('can delete an account head from the table', function () {
        $head = AccountHead::factory()->create();

        livewire(ListAccountHeads::class)
            ->callTableAction('delete', $head);

        expect(AccountHead::find($head->id))->toBeNull();
    });

    it('has correct navigation properties', function () {
        expect(AccountHeadResource::getNavigationLabel())->toBe('Account Heads')
            ->and(AccountHeadResource::getNavigationSort())->toBe(1);
    });

    it('soft-deletes the record and retains it in the database', function () {
        $head = AccountHead::factory()->create();

        livewire(ListAccountHeads::class)
            ->callTableAction('delete', $head);

        // Record should not appear via normal query
        expect(AccountHead::find($head->id))->toBeNull();
        // But should still exist in the database with a deleted_at timestamp
        expect(AccountHead::withTrashed()->find($head->id))->not->toBeNull()
            ->and(AccountHead::withTrashed()->find($head->id)->deleted_at)->not->toBeNull();
    });

    it('can filter trashed records', function () {
        $active = AccountHead::factory()->create();
        $trashed = AccountHead::factory()->create();
        $trashed->delete();

        livewire(ListAccountHeads::class)
            ->assertCanSeeTableRecords([$active])
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$trashed]);
    });

    it('can filter by active status', function () {
        $active = AccountHead::factory()->create(['is_active' => true]);
        $inactive = AccountHead::factory()->create(['is_active' => false]);

        livewire(ListAccountHeads::class)
            ->filterTable('is_active', true)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive]);
    });

    it('redirects to the list after creating an account head', function () {
        livewire(CreateAccountHead::class)
            ->fillForm([
                'name' => 'Redirect Test Head',
                'group_name' => 'Current Assets',
                'is_active' => true,
            ])
            ->call('create')
            ->assertRedirect(AccountHeadResource::getUrl('index'));
    });

    it('redirects to the list after editing an account head', function () {
        $head = AccountHead::factory()->create();

        livewire(EditAccountHead::class, ['record' => $head->getRouteKey()])
            ->fillForm(['name' => 'Updated Name'])
            ->call('save')
            ->assertRedirect(AccountHeadResource::getUrl('index'));
    });

    it('shows empty state with import guidance when no heads exist', function () {
        livewire(ListAccountHeads::class)
            ->assertSee('No account heads yet')
            ->assertSee('Import your chart of accounts from Tally to get started.')
            ->assertTableActionExists('import_tally_empty');
    });

    it('shows a validation error instead of a database exception for duplicate account heads', function () {
        AccountHead::factory()->create([
            'company_id' => tenant()->id,
            'name' => 'Bank Charges',
            'group_name' => 'Indirect Expenses',
        ]);

        livewire(CreateAccountHead::class)
            ->fillForm([
                'name' => 'Bank Charges',
                'group_name' => 'Indirect Expenses',
            ])
            ->call('create')
            ->assertHasFormErrors(['name']);

        expect(AccountHead::where('name', 'Bank Charges')->count())->toBe(1);
    });

    it('allows same account head name with different group', function () {
        AccountHead::factory()->create([
            'company_id' => tenant()->id,
            'name' => 'Bank Charges',
            'group_name' => 'Indirect Expenses',
        ]);

        livewire(CreateAccountHead::class)
            ->fillForm([
                'name' => 'Bank Charges',
                'group_name' => 'Direct Expenses',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(AccountHead::where('name', 'Bank Charges')->count())->toBe(2);
    });

    it('allows editing an account head without triggering duplicate validation on itself', function () {
        $head = AccountHead::factory()->create([
            'company_id' => tenant()->id,
            'name' => 'Bank Charges',
            'group_name' => 'Indirect Expenses',
        ]);

        livewire(EditAccountHead::class, ['record' => $head->getRouteKey()])
            ->fillForm([
                'name' => 'Bank Charges',
                'group_name' => 'Indirect Expenses',
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    it('allows creating an account head with a name that was previously soft-deleted', function () {
        $head = AccountHead::factory()->create([
            'company_id' => tenant()->id,
            'name' => 'Bank Charges',
            'group_name' => 'Indirect Expenses',
        ]);
        $head->delete();

        livewire(CreateAccountHead::class)
            ->fillForm([
                'name' => 'Bank Charges',
                'group_name' => 'Indirect Expenses',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(AccountHead::where('name', 'Bank Charges')->count())->toBe(1);
    });

    it('blocks deletion of account head when transactions are mapped', function () {
        $head = AccountHead::factory()->create();
        Transaction::factory()->mapped($head)->count(3)->create();

        livewire(ListAccountHeads::class)
            ->callTableAction('delete', $head)
            ->assertSuccessful()
            ->assertNotified('Cannot delete — 3 transactions are mapped to this head. Reassign them first.');

        expect(AccountHead::find($head->id))->not->toBeNull();
    });

    it('blocks force deletion of account head when transactions are mapped', function () {
        $head = AccountHead::factory()->create();
        $head->delete(); // Needs to be soft-deleted to run forceDelete action in most Filament setups
        Transaction::factory()->mapped($head)->count(2)->create();

        livewire(ListAccountHeads::class)
            ->filterTable('trashed', true)
            ->callTableAction('forceDelete', $head)
            ->assertSuccessful()
            ->assertNotified('Cannot delete — 2 transactions are mapped to this head. Reassign them first.');

        expect(AccountHead::withTrashed()->find($head->id))->not->toBeNull();
    });

    it('blocks deletion of account head with singular grammar when 1 transaction is mapped', function () {
        $head = AccountHead::factory()->create();
        Transaction::factory()->mapped($head)->count(1)->create();

        livewire(ListAccountHeads::class)
            ->callTableAction('delete', $head)
            ->assertSuccessful()
            ->assertNotified('Cannot delete — 1 transaction is mapped to this head. Reassign it first.');

        expect(AccountHead::find($head->id))->not->toBeNull();
    });

    it('blocks bulk deletion of account heads when transactions are mapped', function () {
        $head = AccountHead::factory()->create();
        Transaction::factory()->mapped($head)->count(1)->create();
        $head2 = AccountHead::factory()->create();

        livewire(ListAccountHeads::class)
            ->callTableBulkAction('delete', [$head, $head2])
            ->assertNotified('Cannot delete — 1 transaction is mapped to this head. Reassign it first.');

        expect(AccountHead::find($head->id))->not->toBeNull();
        expect(AccountHead::find($head2->id))->not->toBeNull();
    });

    it('blocks deletion of account head from the edit page when transactions are mapped', function () {
        $head = AccountHead::factory()->create();
        Transaction::factory()->mapped($head)->count(1)->create();

        livewire(EditAccountHead::class, ['record' => $head->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('Cannot delete — 1 transaction is mapped to this head. Reassign it first.');

        expect(AccountHead::find($head->id))->not->toBeNull();
    });
});
