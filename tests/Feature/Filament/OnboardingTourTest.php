<?php

use App\Livewire\OnboardingTour;
use Livewire\Livewire;

describe('Onboarding Tour', function () {
    it('has toured_pages column on users table', function () {
        $user = asUser();

        expect($user->toured_pages)->toBeNull();
    });

    it('auto-triggers tour for unvisited page', function () {
        $user = asUser();

        Livewire::actingAs($user)
            ->test(OnboardingTour::class, ['pageId' => 'dashboard'])
            ->assertSet('showTour', true)
            ->assertSet('pageId', 'dashboard');
    });

    it('does not auto-trigger for visited page', function () {
        $user = asUser();
        $user->update(['toured_pages' => ['dashboard' => true]]);

        Livewire::actingAs($user)
            ->test(OnboardingTour::class, ['pageId' => 'dashboard'])
            ->assertSet('showTour', false);
    });

    it('marks page as toured on completion', function () {
        $user = asUser();

        Livewire::actingAs($user)
            ->test(OnboardingTour::class, ['pageId' => 'transactions'])
            ->call('completeTour');

        expect($user->fresh()->toured_pages)->toHaveKey('transactions');
    });

    it('preserves other pages when completing a tour', function () {
        $user = asUser();
        $user->update(['toured_pages' => ['dashboard' => true]]);

        Livewire::actingAs($user)
            ->test(OnboardingTour::class, ['pageId' => 'transactions'])
            ->call('completeTour');

        $pages = $user->fresh()->toured_pages;
        expect($pages)->toHaveKey('dashboard')
            ->and($pages)->toHaveKey('transactions');
    });

    it('can start tour on demand via event', function () {
        $user = asUser();
        $user->update(['toured_pages' => ['dashboard' => true]]);

        Livewire::actingAs($user)
            ->test(OnboardingTour::class, ['pageId' => 'dashboard'])
            ->assertSet('showTour', false)
            ->call('startTour')
            ->assertSet('showTour', true);
    });

    it('passes tour steps from config to the view', function () {
        $user = asUser();

        $component = Livewire::actingAs($user)
            ->test(OnboardingTour::class, ['pageId' => 'dashboard']);

        $steps = $component->get('steps');
        expect($steps)->toBeArray()
            ->and($steps)->not->toBeEmpty()
            ->and($steps[0])->toHaveKeys(['title', 'description', 'element']);
    });

    it('loads steps for review-queue tour', function () {
        $component = Livewire::actingAs(asUser())
            ->test(OnboardingTour::class, ['pageId' => 'review-queue']);

        $steps = $component->get('steps');
        expect($steps)->not->toBeEmpty();
    });

    it('loads steps for inbound-emails tour', function () {
        $component = Livewire::actingAs(asUser())
            ->test(OnboardingTour::class, ['pageId' => 'inbound-emails']);

        $steps = $component->get('steps');
        expect($steps)->not->toBeEmpty();
    });
});
