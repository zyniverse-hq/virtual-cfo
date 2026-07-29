<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Illuminate\Contracts\View\View;

trait HasPageTour
{
    protected function getPageTourAction(): Action
    {
        return Action::make('page_tour')
            ->label('Page Tour')
            ->icon('heroicon-o-academic-cap')
            ->color('gray')
            ->extraAttributes([
                'x-on:click.prevent' => "\$dispatch('start-page-tour')",
            ]);
    }

    protected function getPageTourFooter(string $pageId): ?View
    {
        return view('livewire.page-tour-embed', ['pageId' => $pageId]);
    }
}
