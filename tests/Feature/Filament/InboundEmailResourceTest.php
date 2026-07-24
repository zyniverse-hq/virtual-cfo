<?php

use App\Enums\InboundEmailStatus;
use App\Enums\NavigationGroup;
use App\Enums\UserRole;
use App\Filament\Resources\InboundEmailResource;
use App\Filament\Resources\InboundEmailResource\Pages\ListInboundEmails;
use App\Filament\Resources\InboundEmailResource\Pages\ViewInboundEmail;
use App\Models\Company;
use App\Models\InboundEmail;
use App\Models\User;

use function Pest\Livewire\livewire;

describe('InboundEmail Resource', function () {
    describe('Access control', function () {
        it('renders for admin users', function () {
            asUser(role: UserRole::Admin);

            livewire(ListInboundEmails::class)
                ->assertSuccessful();
        });

        it('denies access to viewer users', function () {
            asUser(User::factory()->viewer()->create(), UserRole::Viewer);

            livewire(ListInboundEmails::class)
                ->assertForbidden();
        });

        it('denies access to accountant users', function () {
            asUser(User::factory()->accountant()->create(), UserRole::Accountant);

            livewire(ListInboundEmails::class)
                ->assertForbidden();
        });
    });

    describe('Table', function () {
        it('shows inbound email records in the table', function () {
            asUser(role: UserRole::Admin);
            $company = tenant();

            $inboundEmail = InboundEmail::factory()->create([
                'company_id' => $company->id,
                'status' => InboundEmailStatus::Processed,
            ]);

            livewire(ListInboundEmails::class)
                ->assertCanSeeTableRecords([$inboundEmail]);
        });

        it('does not show inbound emails from other companies', function () {
            asUser(role: UserRole::Admin);

            $otherCompany = Company::factory()->create();
            $otherEmail = InboundEmail::factory()->create([
                'company_id' => $otherCompany->id,
            ]);

            livewire(ListInboundEmails::class)
                ->assertCanNotSeeTableRecords([$otherEmail]);
        });

        it('renders the subject as the description in from_address column', function () {
            asUser(role: UserRole::Admin);
            $company = tenant();

            $email = InboundEmail::factory()->create([
                'company_id' => $company->id,
                'from_address' => 'sender@example.com',
                'subject' => 'Important Monthly Statement',
            ]);

            livewire(ListInboundEmails::class)
                ->assertTableColumnHasDescription('from_address', 'Important Monthly Statement', $email);
        });
    });

    describe('Filters', function () {
        it('filters by status', function () {
            asUser(role: UserRole::Admin);
            $company = tenant();

            $processed = InboundEmail::factory()->create([
                'company_id' => $company->id,
                'status' => InboundEmailStatus::Processed,
            ]);

            $rejected = InboundEmail::factory()->create([
                'company_id' => $company->id,
                'status' => InboundEmailStatus::Rejected,
            ]);

            livewire(ListInboundEmails::class)
                ->filterTable('status', InboundEmailStatus::Processed->value)
                ->assertCanSeeTableRecords([$processed])
                ->assertCanNotSeeTableRecords([$rejected]);
        });
    });

    describe('View page', function () {
        it('renders the view page with email details', function () {
            asUser(role: UserRole::Admin);
            $company = tenant();

            $inboundEmail = InboundEmail::factory()->create([
                'company_id' => $company->id,
                'status' => InboundEmailStatus::Processed,
                'subject' => 'Invoice for January',
            ]);

            livewire(ViewInboundEmail::class, ['record' => $inboundEmail->getRouteKey()])
                ->assertSuccessful()
                ->assertSeeText('Invoice for January');
        });
    });

    describe('Navigation', function () {
        it('is in the Monitoring navigation group', function () {
            expect(InboundEmailResource::getNavigationGroup())->toBe(NavigationGroup::Monitoring);
        });
    });

    describe('Read-only', function () {
        it('has no create action', function () {
            expect(InboundEmailResource::canCreate())->toBeFalse();
        });
    });
});
