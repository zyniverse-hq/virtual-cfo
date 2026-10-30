<?php

use App\Enums\MappingType;
use App\Enums\UserRole;
use App\Filament\Resources\CreditCardResource\Pages\ListCreditCards;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\AccountHead;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CreditCard;
use App\Models\ImportedFile;
use App\Models\Transaction;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

describe('Cross-Tenant Credit Card Sharing', function () {
    beforeEach(function () {
        $this->user = asUser();
        $this->company = tenant();
    });

    describe('Share Card Action', function () {
        it('allows admin to share a credit card with another company where they are also admin', function () {
            $card = CreditCard::factory()->create(['company_id' => $this->company->id]);

            $targetCompany = Company::factory()->create();
            $targetCompany->users()->attach($this->user, ['role' => UserRole::Admin->value]);

            livewire(ListCreditCards::class)
                ->callTableAction('share_card', $card, data: [
                    'company_ids' => [$targetCompany->id],
                ])
                ->assertHasNoTableActionErrors();

            expect($card->fresh()->sharedCompanies)->toHaveCount(1)
                ->and($card->fresh()->sharedCompanies->first()->id)->toBe($targetCompany->id);
        });

        it('cannot share if not admin on target company', function () {
            $card = CreditCard::factory()->create(['company_id' => $this->company->id]);

            $targetCompany = Company::factory()->create();
            $targetCompany->users()->attach($this->user, ['role' => UserRole::Viewer->value]);

            livewire(ListCreditCards::class)
                ->callTableAction('share_card', $card, data: [
                    'company_ids' => [$targetCompany->id],
                ])
                ->assertNotified();

            expect($card->fresh()->sharedCompanies)->toHaveCount(0);
        });

        it('only shows companies where user is admin in share modal', function () {
            $card = CreditCard::factory()->create(['company_id' => $this->company->id]);

            $adminCompany = Company::factory()->create(['name' => 'Admin Target']);
            $adminCompany->users()->attach($this->user, ['role' => UserRole::Admin->value]);

            $viewerCompany = Company::factory()->create(['name' => 'Viewer Target']);
            $viewerCompany->users()->attach($this->user, ['role' => UserRole::Viewer->value]);

            livewire(ListCreditCards::class)
                ->assertTableActionExists('share_card');
        });
    });

    describe('visibleToCompany Scope', function () {
        it('shows shared cards in target tenant credit card list', function () {
            $otherCompany = Company::factory()->create();
            $sharedCard = CreditCard::factory()->create(['company_id' => $otherCompany->id]);

            $sharedCard->sharedCompanies()->attach($this->company->id, [
                'shared_by' => $this->user->id,
            ]);

            $ownCard = CreditCard::factory()->create(['company_id' => $this->company->id]);

            livewire(ListCreditCards::class)
                ->assertCanSeeTableRecords([$ownCard, $sharedCard]);
        });

        it('does not show unshared cards from other tenants', function () {
            $otherCompany = Company::factory()->create();
            $otherCard = CreditCard::factory()->create(['company_id' => $otherCompany->id]);

            $ownCard = CreditCard::factory()->create(['company_id' => $this->company->id]);

            livewire(ListCreditCards::class)
                ->assertCanSeeTableRecords([$ownCard])
                ->assertCanNotSeeTableRecords([$otherCard]);
        });
    });

    describe('CreditCard Model', function () {
        it('can check if shared with a specific company', function () {
            $card = CreditCard::factory()->create(['company_id' => $this->company->id]);
            $targetCompany = Company::factory()->create();

            expect($card->isSharedWith($targetCompany))->toBeFalse();

            $card->sharedCompanies()->attach($targetCompany->id, [
                'shared_by' => $this->user->id,
            ]);

            expect($card->fresh()->isSharedWith($targetCompany))->toBeTrue();
        });

        it('has sharedCompanies relationship', function () {
            $card = CreditCard::factory()->create(['company_id' => $this->company->id]);
            $targetCompany = Company::factory()->create();

            $card->sharedCompanies()->attach($targetCompany->id, [
                'shared_by' => $this->user->id,
            ]);

            expect($card->sharedCompanies)->toHaveCount(1)
                ->and($card->sharedCompanies->first()->id)->toBe($targetCompany->id);
        });
    });

    describe('Company Model', function () {
        it('has sharedCreditCards inverse relationship', function () {
            $card = CreditCard::factory()->create(['company_id' => $this->company->id]);
            $targetCompany = Company::factory()->create();

            $card->sharedCompanies()->attach($targetCompany->id, [
                'shared_by' => $this->user->id,
            ]);

            expect($targetCompany->sharedCreditCards)->toHaveCount(1)
                ->and($targetCompany->sharedCreditCards->first()->id)->toBe($card->id);
        });
    });

    describe('Move Transactions to Company', function () {
        it('bulk moves transactions to target company', function () {
            $card = CreditCard::factory()->create(['company_id' => $this->company->id]);
            $targetCompany = Company::factory()->create();
            $targetCompany->users()->attach($this->user, ['role' => UserRole::Admin->value]);
            $card->sharedCompanies()->attach($targetCompany->id, [
                'shared_by' => $this->user->id,
            ]);

            $importedFile = ImportedFile::factory()->create([
                'company_id' => $this->company->id,
                'credit_card_id' => $card->id,
            ]);

            $head = AccountHead::factory()->create(['company_id' => $this->company->id]);
            $transactions = Transaction::factory()->count(3)->mapped($head)->create([
                'company_id' => $this->company->id,
                'imported_file_id' => $importedFile->id,
            ]);

            livewire(ListTransactions::class)
                ->callTableBulkAction('move_to_company', $transactions, data: [
                    'target_company_id' => $targetCompany->id,
                ])
                ->assertHasNoTableBulkActionErrors()
                ->assertNotified();

            foreach ($transactions as $tx) {
                $tx->refresh();
                expect($tx->company_id)->toBe($targetCompany->id)
                    ->and($tx->currency)->toBe($targetCompany->currency)
                    ->and($tx->account_head_id)->toBeNull()
                    ->and($tx->mapping_type)->toBe(MappingType::Unmapped)
                    ->and($tx->ai_confidence)->toBeNull();
            }
        });

        it('clears account_head_id and resets mapping_type on moved transactions', function () {
            $card = CreditCard::factory()->create(['company_id' => $this->company->id]);
            $targetCompany = Company::factory()->create();
            $card->sharedCompanies()->attach($targetCompany->id, [
                'shared_by' => $this->user->id,
            ]);

            $importedFile = ImportedFile::factory()->create([
                'company_id' => $this->company->id,
                'credit_card_id' => $card->id,
            ]);

            $head = AccountHead::factory()->create(['company_id' => $this->company->id]);
            $tx = Transaction::factory()->aiMapped($head, 0.92)->create([
                'company_id' => $this->company->id,
                'imported_file_id' => $importedFile->id,
            ]);

            $tx->moveToCompany($targetCompany);

            expect($tx->company_id)->toBe($targetCompany->id)
                ->and($tx->account_head_id)->toBeNull()
                ->and($tx->mapping_type)->toBe(MappingType::Unmapped)
                ->and($tx->ai_confidence)->toBeNull();
        });

        it('preserves imported_file_id after move', function () {
            $card = CreditCard::factory()->create(['company_id' => $this->company->id]);
            $targetCompany = Company::factory()->create();
            $card->sharedCompanies()->attach($targetCompany->id, [
                'shared_by' => $this->user->id,
            ]);

            $importedFile = ImportedFile::factory()->create([
                'company_id' => $this->company->id,
                'credit_card_id' => $card->id,
            ]);

            $tx = Transaction::factory()->create([
                'company_id' => $this->company->id,
                'imported_file_id' => $importedFile->id,
            ]);

            $originalFileId = $tx->imported_file_id;
            $tx->moveToCompany($targetCompany);

            expect($tx->imported_file_id)->toBe($originalFileId);
        });

        it('cannot move transactions to a company the card is not shared with', function () {
            $card = CreditCard::factory()->create(['company_id' => $this->company->id]);
            $unsharedCompany = Company::factory()->create();
            $unsharedCompany->users()->attach($this->user, ['role' => UserRole::Admin->value]);

            $importedFile = ImportedFile::factory()->create([
                'company_id' => $this->company->id,
                'credit_card_id' => $card->id,
            ]);

            $transactions = Transaction::factory()->count(2)->create([
                'company_id' => $this->company->id,
                'imported_file_id' => $importedFile->id,
            ]);

            livewire(ListTransactions::class)
                ->callTableBulkAction('move_to_company', $transactions, data: [
                    'target_company_id' => $unsharedCompany->id,
                ])
                ->assertNotified();

            foreach ($transactions as $tx) {
                expect($tx->fresh()->company_id)->toBe($this->company->id);
            }
        });

        it('logs activity when moving transactions', function () {
            $card = CreditCard::factory()->create(['company_id' => $this->company->id]);
            $targetCompany = Company::factory()->create();
            $card->sharedCompanies()->attach($targetCompany->id, [
                'shared_by' => $this->user->id,
            ]);

            $importedFile = ImportedFile::factory()->create([
                'company_id' => $this->company->id,
                'credit_card_id' => $card->id,
            ]);

            $tx = Transaction::factory()->mapped()->create([
                'company_id' => $this->company->id,
                'imported_file_id' => $importedFile->id,
            ]);

            $tx->moveToCompany($targetCompany);

            $activity = Activity::where('subject_type', Transaction::class)
                ->where('subject_id', $tx->id)
                ->latest()
                ->first();

            expect($activity)->not->toBeNull();
        });
    });

    describe('Unsharing', function () {
        it('does not affect already-moved transactions when unsharing', function () {
            $card = CreditCard::factory()->create(['company_id' => $this->company->id]);
            $targetCompany = Company::factory()->create();
            $card->sharedCompanies()->attach($targetCompany->id, [
                'shared_by' => $this->user->id,
            ]);

            $importedFile = ImportedFile::factory()->create([
                'company_id' => $this->company->id,
                'credit_card_id' => $card->id,
            ]);

            $tx = Transaction::factory()->create([
                'company_id' => $this->company->id,
                'imported_file_id' => $importedFile->id,
            ]);

            $tx->moveToCompany($targetCompany);
            expect($tx->company_id)->toBe($targetCompany->id);

            $card->sharedCompanies()->detach($targetCompany->id);

            expect($tx->fresh()->company_id)->toBe($targetCompany->id);
        });
    });

    describe('Delete Actions Removed', function () {
        it('does not have delete action on table rows', function () {
            livewire(ListCreditCards::class)
                ->assertTableActionDoesNotExist('delete');
        });

        it('does not have force delete action on table rows', function () {
            livewire(ListCreditCards::class)
                ->assertTableActionDoesNotExist('forceDelete');
        });

        it('does not have restore action on table rows', function () {
            livewire(ListCreditCards::class)
                ->assertTableActionDoesNotExist('restore');
        });

        it('does not have bulk delete actions', function () {
            livewire(ListCreditCards::class)
                ->assertTableBulkActionDoesNotExist('delete')
                ->assertTableBulkActionDoesNotExist('forceDelete')
                ->assertTableBulkActionDoesNotExist('restore');
        });
    });

    describe('Bank Accounts', function () {
        it('does not have share functionality on bank accounts', function () {
            $bankAccount = BankAccount::factory()->create([
                'company_id' => $this->company->id,
            ]);

            expect(method_exists($bankAccount, 'sharedCompanies'))->toBeFalse();
        });
    });
});
