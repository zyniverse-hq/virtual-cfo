<?php

use App\Filament\Widgets\QuickNoteWidget;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

function storedQuickNotes(User $user, Company $company): ?string
{
    /** @var string|null */
    return DB::table('company_user')
        ->where('user_id', $user->id)
        ->where('company_id', $company->id)
        ->value('quick_notes');
}

it('renders the quick note widget on the dashboard', function () {
    /** @var User $user */
    $user = User::factory()->admin()->create();
    $company = Company::factory()->create();
    $user->companies()->attach($company);

    actingAs($user)
        ->get('/admin/'.$company->id)
        // @phpstan-ignore method.notFound
        ->assertSeeLivewire(QuickNoteWidget::class);
});

it('saves quick notes encrypted at rest and notifies success', function () {
    /** @var User $user */
    $user = User::factory()->admin()->create();
    $company = Company::factory()->create();
    $user->companies()->attach($company);

    actingAs($user);
    Filament::setTenant($company);

    livewire(QuickNoteWidget::class)
        ->fillForm(['notes' => 'This is a new quick note.'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Notes saved successfully');

    $stored = storedQuickNotes($user, $company);

    expect($stored)->not->toBe('This is a new quick note.')
        ->and(Crypt::decryptString((string) $stored))->toBe('This is a new quick note.');
});

it('loads previously saved notes decrypted on mount', function () {
    /** @var User $user */
    $user = User::factory()->admin()->create();
    $company = Company::factory()->create();
    $user->companies()->attach($company, ['quick_notes' => Crypt::encryptString('Remember GST filing')]);

    actingAs($user);
    Filament::setTenant($company);

    livewire(QuickNoteWidget::class)
        ->assertFormSet(['notes' => 'Remember GST filing']);
});

it('clears stored notes when saved empty', function () {
    /** @var User $user */
    $user = User::factory()->admin()->create();
    $company = Company::factory()->create();
    $user->companies()->attach($company, ['quick_notes' => Crypt::encryptString('Old')]);

    actingAs($user);
    Filament::setTenant($company);

    livewire(QuickNoteWidget::class)
        ->fillForm(['notes' => ''])
        ->call('save')
        ->assertNotified('Notes saved successfully');

    expect(storedQuickNotes($user, $company))->toBeNull();
});

it('handles null tenant gracefully on mount', function () {
    /** @var User $user */
    $user = User::factory()->admin()->create();

    actingAs($user);
    Filament::setTenant(null);

    livewire(QuickNoteWidget::class)
        ->assertFormSet(['notes' => null]);
});

it('warns and persists nothing when there is no tenant on save', function () {
    /** @var User $user */
    $user = User::factory()->admin()->create();
    $company = Company::factory()->create();
    $user->companies()->attach($company);

    actingAs($user);
    Filament::setTenant(null);

    livewire(QuickNoteWidget::class)
        ->fillForm(['notes' => 'This note will not be saved'])
        ->call('save')
        ->assertNotified('Notes could not be saved');

    expect(storedQuickNotes($user, $company))->toBeNull();
});

it('warns and persists nothing when the user is not a member of the tenant', function () {
    /** @var User $user */
    $user = User::factory()->admin()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();
    $member->companies()->attach($company);

    actingAs($user);
    Filament::setTenant($company);

    livewire(QuickNoteWidget::class)
        ->fillForm(['notes' => 'Should not be stored'])
        ->call('save')
        ->assertNotified('Notes could not be saved');

    expect(DB::table('company_user')->where('company_id', $company->id)->whereNotNull('quick_notes')->count())->toBe(0);
});
