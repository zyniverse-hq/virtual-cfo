<?php

use App\Enums\MatchType;
use App\Filament\Resources\ImportedFileResource\Pages\ViewImportedFile;
use App\Filament\Resources\ImportedFileResource\RelationManagers\TransactionsRelationManager;
use App\Models\AccountHead;
use App\Models\HeadMapping;
use App\Models\ImportedFile;
use App\Models\Transaction;

use function Pest\Livewire\livewire;

describe('ImportedFile Transactions RelationManager', function () {
    beforeEach(function () {
        asUser();
    });

    it('renders successfully on the view page', function () {
        $file = ImportedFile::factory()->create();

        livewire(ViewImportedFile::class, ['record' => $file->getRouteKey()])
            ->assertSeeLivewire(TransactionsRelationManager::class);
    });

    it('shows transactions belonging to the file', function () {
        $file = ImportedFile::factory()->create();
        $transactions = Transaction::factory()->count(3)->for($file, 'importedFile')->create();

        livewire(TransactionsRelationManager::class, [
            'ownerRecord' => $file,
            'pageClass' => ViewImportedFile::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($transactions);
    });

    it('does not show transactions from other files', function () {
        $file = ImportedFile::factory()->create();
        $otherFile = ImportedFile::factory()->create();

        $ours = Transaction::factory()->for($file, 'importedFile')->create();
        $theirs = Transaction::factory()->for($otherFile, 'importedFile')->create();

        livewire(TransactionsRelationManager::class, [
            'ownerRecord' => $file,
            'pageClass' => ViewImportedFile::class,
        ])
            ->assertCanSeeTableRecords([$ours])
            ->assertCanNotSeeTableRecords([$theirs]);
    });

    it('shows an empty state when the file has no transactions', function () {
        $file = ImportedFile::factory()->create();

        livewire(TransactionsRelationManager::class, [
            'ownerRecord' => $file,
            'pageClass' => ViewImportedFile::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([]);
    });

    it('has an assign_head action for each transaction row', function () {
        $file = ImportedFile::factory()->create();
        $transaction = Transaction::factory()->for($file, 'importedFile')->create();

        livewire(TransactionsRelationManager::class, [
            'ownerRecord' => $file,
            'pageClass' => ViewImportedFile::class,
        ])
            ->assertTableActionExists('assign_head', record: $transaction);
    });

    it('has a bulk assign_head action', function () {
        $file = ImportedFile::factory()->create();

        livewire(TransactionsRelationManager::class, [
            'ownerRecord' => $file,
            'pageClass' => ViewImportedFile::class,
        ])
            ->assertTableBulkActionExists('bulk_assign_head');
    });

    it('has an export header action group', function () {
        $file = ImportedFile::factory()->create();

        livewire(TransactionsRelationManager::class, [
            'ownerRecord' => $file,
            'pageClass' => ViewImportedFile::class,
        ])
            ->assertTableActionExists('run_ai_matching');
    });

    it('has no create action', function () {
        $file = ImportedFile::factory()->create();

        livewire(TransactionsRelationManager::class, [
            'ownerRecord' => $file,
            'pageClass' => ViewImportedFile::class,
        ])
            ->assertTableActionDoesNotExist('create');
    });

    it('shows a danger notification and does not create a duplicate mapping rule', function () {
        $head = AccountHead::factory()->create();
        $file = ImportedFile::factory()->create();
        $transaction = Transaction::factory()
            ->mapped($head)
            ->for($file, 'importedFile')
            ->create(['description' => 'SALARY CREDIT']);

        HeadMapping::factory()->create([
            'company_id' => tenant()->id,
            'pattern' => 'SALARY CREDIT',
            'match_type' => MatchType::Contains,
            'account_head_id' => $head->id,
        ]);

        $countBefore = HeadMapping::count();

        livewire(TransactionsRelationManager::class, [
            'ownerRecord' => $file,
            'pageClass' => ViewImportedFile::class,
        ])
            ->callTableAction('create_rule', $transaction, [
                'pattern' => 'SALARY CREDIT',
                'match_type' => MatchType::Contains,
                'account_head_id' => $head->id,
                'bank_name' => null,
            ])
            ->assertNotified('Duplicate rule');

        expect(HeadMapping::count())->toBe($countBefore);
    });
});
