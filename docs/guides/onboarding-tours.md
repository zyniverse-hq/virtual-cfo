# Onboarding Tours — Developer Guide

Page-specific onboarding tours use Driver.js (loaded via CDN) and a Livewire component to guide users through each page's UI. Tours auto-trigger on a user's first visit and can be restarted via a header action.

## How It Works

1. Tour steps are defined in `config/tours.php` keyed by page identifier
2. The `OnboardingTour` Livewire component receives a `pageId` prop on each page
3. On mount, it checks `user->toured_pages[pageId]` — if absent, the tour auto-starts
4. On completion, the page key is written to `toured_pages` (JSONB column on `users`)
5. A "Page Tour" header action on each list page lets users replay the tour

## Adding a Tour to a New Page

### Step 1: Define tour steps in config

Add an entry to `config/tours.php`:

```php
'your-page' => [
    [
        'title' => 'Page Title',
        'description' => 'Workflow context — how this page fits in the pipeline.',
        'element' => null, // null = centered popover (intro step)
    ],
    [
        'title' => 'Key Feature',
        'description' => 'What this button/column/widget does.',
        'element' => '[data-tour="your-element"]', // CSS selector
    ],
],
```

**Step content guidelines:**
- First step is always an intro (no element) explaining the page's role in the workflow
- Element steps should be short — one sentence describing what the element does
- Use `data-tour="..."` attributes on Filament components where possible for stable selectors
- Fallback to `[href*="..."]` or Filament's existing `data-*` attributes when custom attributes aren't feasible

### Step 2: Add the Livewire component to the page

In the page's List class (e.g., `ListYourResources.php`):

```php
use App\Filament\Concerns\HasPageTour;

class ListYourResources extends ListRecords
{
    use HasPageTour;

    protected function getHeaderActions(): array
    {
        return [
            // ... existing actions
            $this->getPageTourAction(),
        ];
    }

    public function getFooter(): ?\Illuminate\Contracts\View\View
    {
        return $this->getPageTourFooter('your-page');
    }
}
```

The `HasPageTour` trait provides the standardized `getPageTourAction()` for the header and `getPageTourFooter($pageId)` which renders the `OnboardingTour` Livewire component.

### Step 3: Test

Add a test in `tests/Feature/Filament/OnboardingTourTest.php`:

```php
it('auto-triggers tour on first visit to your-page', function () {
    $user = asUser();

    // Assert toured_pages does not contain the key
    expect($user->toured_pages)->not->toHaveKey('your-page');

    // After completing, key should be set
    Livewire::actingAs($user)
        ->test(OnboardingTour::class, ['pageId' => 'your-page'])
        ->call('completeTour');

    expect($user->fresh()->toured_pages)->toHaveKey('your-page');
});
```

### Step 4: Verify

1. Clear your `toured_pages` for the page: `User::find(1)->update(['toured_pages' => null])`
2. Navigate to the page — tour should auto-start
3. Complete the tour — revisit the page, tour should not auto-start
4. Click "Page Tour" header action — tour should start again

## CSS Selector Strategy

Prefer stable selectors in this order:

1. `[data-tour="element-name"]` — custom attributes you add (most stable)
2. `[data-widget]`, `[data-sidebar-group]` — Filament's built-in data attributes
3. `[href*="route-slug"]` — URL-based (breaks if route slugs change)
4. `.fi-*` classes — Filament's CSS classes (may change between versions)

## Extending Phase 1

Phase 1 covers 8 core workflow pages. To complete coverage, add tours for:

- Bank Accounts — account management, PDF password setup
- Credit Cards — card management, statement upload
- Recurring Patterns — auto-detected patterns, editing rules
- Team Members — invitation flow, role management
- Activity Log — audit trail, filtering by action type
- Reports — report generation, date range selection
