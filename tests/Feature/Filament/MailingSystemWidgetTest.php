<?php

use App\Enums\InboundEmailStatus;
use App\Enums\UserRole;
use App\Filament\Widgets\MailingSystemWidget;
use App\Models\Company;
use App\Models\InboundEmail;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

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

    it('renders the three stat cards through the public component surface', function () {
        livewire(MailingSystemWidget::class)
            ->assertSuccessful()
            ->assertSee('Emails Received (7 Days)')
            ->assertSee('Rejected')
            ->assertSee('No Attachments');
    });

    it('renders tenant-scoped counts and excludes other companies', function () {
        $company = tenant();

        // Active company — within the 7-day window.
        InboundEmail::factory()->count(2)->create([
            'company_id' => $company->id,
            'received_at' => Carbon::now()->subDays(2),
        ]);

        // Active company — older than 7 days, excluded from the recent count.
        InboundEmail::factory()->create([
            'company_id' => $company->id,
            'received_at' => Carbon::now()->subDays(10),
        ]);

        // Active company — a single rejected email within the window.
        InboundEmail::factory()->create([
            'company_id' => $company->id,
            'received_at' => Carbon::now()->subDays(2),
            'status' => InboundEmailStatus::Rejected,
        ]);

        // Active company — no-attachment emails within the window.
        InboundEmail::factory()->count(3)->create([
            'company_id' => $company->id,
            'received_at' => Carbon::now()->subDays(2),
            'status' => InboundEmailStatus::NoAttachments,
        ]);

        // Another company — must be excluded entirely from every stat. If tenant
        // scoping leaked, the rejected count would jump from 1 to 9.
        $otherCompany = Company::factory()->create();
        InboundEmail::factory()->count(8)->create([
            'company_id' => $otherCompany->id,
            'received_at' => Carbon::now()->subDays(2),
            'status' => InboundEmailStatus::Rejected,
        ]);

        // Expected for the active company:
        //   recent (any status, within 7 days) = 2 + 1 rejected + 3 no-attachment = 6
        //   rejected (all time)                = 1
        //   no attachments (all time)          = 3
        livewire(MailingSystemWidget::class)
            ->assertSuccessful()
            ->assertSeeText('Emails Received (7 Days)')
            ->assertSeeText('6')
            ->assertSeeText('No Attachments')
            ->assertSeeText('3')
            ->assertSeeText('Rejected')
            // The other company's 8 rejected emails must not leak into any stat.
            ->assertDontSeeText('9');
    });
});
