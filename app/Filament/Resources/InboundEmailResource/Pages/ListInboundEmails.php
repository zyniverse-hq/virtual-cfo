<?php

namespace App\Filament\Resources\InboundEmailResource\Pages;

use App\Filament\Resources\InboundEmailResource;
use Filament\Resources\Pages\ListRecords;

class ListInboundEmails extends ListRecords
{
    protected static string $resource = InboundEmailResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('page_tour')
                ->label('Page Tour')
                ->icon('heroicon-o-academic-cap')
                ->color('gray')
                ->extraAttributes([
                    'x-on:click.prevent' => "\$dispatch('start-page-tour')",
                ]),
        ];
    }

    public function getFooter(): ?\Illuminate\Contracts\View\View
    {
        return view('livewire.page-tour-embed', ['pageId' => 'inbound-emails']);
    }
}
