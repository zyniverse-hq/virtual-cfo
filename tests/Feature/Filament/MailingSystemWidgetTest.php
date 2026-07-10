<?php

use App\Enums\InboundEmailStatus;
use App\Filament\Widgets\MailingSystemWidget;
use App\Models\Company;
use App\Models\InboundEmail;
use Illuminate\Support\Carbon;

use function Pest\Livewire\livewire;

describe('MailingSystemWidget', function () {
    beforeEach(function () {
        asUser();
    });

    it('can render', function () {
        livewire(MailingSystemWidget::class)->assertSuccessful();
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

        livewire(MailingSystemWidget::class)
            ->assertSee('Emails Received (7 Days)')
            ->assertSeeHtml('6')
            ->assertSee('Rejected')
            ->assertSeeHtml('1')
            ->assertSee('No Attachments')
            ->assertSeeHtml('3');
    });
});
