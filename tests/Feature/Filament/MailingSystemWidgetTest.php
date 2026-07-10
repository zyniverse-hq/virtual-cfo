<?php

use App\Enums\InboundEmailStatus;
use App\Enums\UserRole;
use App\Filament\Widgets\MailingSystemWidget;
use App\Models\Company;
use App\Models\InboundEmail;
use App\Models\User;
use Illuminate\Support\Carbon;

describe('MailingSystemWidget', function () {
    beforeEach(function () {
        asUser();
    });

    describe('Access control', function () {
        it('renders for authorized users', function () {
            asUser(role: UserRole::Admin);
            expect(MailingSystemWidget::canView())->toBeTrue();
        });

        it('denies access to viewer users', function () {
            asUser(User::factory()->viewer()->create(), UserRole::Viewer);
            expect(MailingSystemWidget::canView())->toBeFalse();
        });

        it('denies access to accountant users', function () {
            asUser(User::factory()->accountant()->create(), UserRole::Accountant);
            expect(MailingSystemWidget::canView())->toBeFalse();
        });
    });

    it('shows correct counts and isolates tenant data', function () {
        $company = tenant();

        // Emails for the active company
        InboundEmail::factory()->count(2)->create([
            'company_id' => $company->id,
            'received_at' => Carbon::now()->subDays(2), // within 7 days
        ]);

        InboundEmail::factory()->create([
            'company_id' => $company->id,
            'received_at' => Carbon::now()->subDays(10), // older than 7 days
        ]);

        InboundEmail::factory()->create([
            'company_id' => $company->id,
            'received_at' => Carbon::now()->subDays(2),
            'status' => InboundEmailStatus::Rejected,
        ]);

        InboundEmail::factory()->count(3)->create([
            'company_id' => $company->id,
            'received_at' => Carbon::now()->subDays(2),
            'status' => InboundEmailStatus::NoAttachments,
        ]);

        // Other company emails (should be ignored completely)
        $otherCompany = Company::factory()->create();
        InboundEmail::factory()->count(5)->create([
            'company_id' => $otherCompany->id,
            'received_at' => Carbon::now(),
            'status' => InboundEmailStatus::Rejected,
        ]);

        // Expected counts for active company:
        // recentEmails = 2 + 1 + 3 = 6
        // rejectedEmails = 1
        // noAttachments = 3

        $widget = new MailingSystemWidget;
        $method = new ReflectionMethod($widget, 'getStats');
        $stats = $method->invoke($widget);

        expect($stats)->toHaveCount(3);

        expect($stats[0]->getLabel())->toBe('Emails Received (7 Days)');
        expect($stats[0]->getValue())->toBe(6);

        expect($stats[1]->getLabel())->toBe('Rejected');
        expect($stats[1]->getValue())->toBe(1);

        expect($stats[2]->getLabel())->toBe('No Attachments');
        expect($stats[2]->getValue())->toBe(3);
    });
});
