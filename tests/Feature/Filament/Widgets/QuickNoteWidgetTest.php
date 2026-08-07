<?php

use App\Filament\Widgets\QuickNoteWidget;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

it('renders the quick note widget on the dashboard', function () {
    /** @var User $user */
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $user->companies()->attach($company);

    actingAs($user)
        ->get('/admin')
        // @phpstan-ignore method.notFound
        ->assertSeeLivewire(QuickNoteWidget::class);
});

it('can save quick notes for the authenticated user and tenant', function () {
    /** @var User $user */
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $user->companies()->attach($company, ['quick_notes' => 'Old note']);

    actingAs($user);
    Filament::setTenant($company);

    livewire(QuickNoteWidget::class)
        ->fillForm([
            'notes' => 'This is a new quick note.',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $user->refresh();

    // @phpstan-ignore property.notFound
    $notes = $user->companies()->where('company_id', $company->id)->first()->pivot->quick_notes;

    /** @var string|null $notes */
    expect($notes)->toBe('This is a new quick note.');
});

it('handles null tenant gracefully on mount', function () {
    /** @var User $user */
    $user = User::factory()->create();

    actingAs($user);
    Filament::setTenant(null);

    livewire(QuickNoteWidget::class)
        ->assertFormSet(['notes' => null]);
});

it('handles null tenant gracefully on save', function () {
    /** @var User $user */
    $user = User::factory()->create();

    actingAs($user);
    Filament::setTenant(null);

    livewire(QuickNoteWidget::class)
        ->fillForm([
            'notes' => 'This note will not be saved',
        ])
        ->call('save')
        ->assertHasNoFormErrors();
});
