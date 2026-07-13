<?php

namespace App\Filament\Resources\AccountHeadResource\Pages;

use App\Filament\Concerns\HasPageTour;
use App\Filament\Resources\AccountHeadResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListAccountHeads extends ListRecords
{
    use HasPageTour;

    protected static string $resource = AccountHeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->icon('heroicon-o-plus'),
            $this->getPageTourAction(),
        ];
    }

    public function getFooter(): ?View
    {
        return $this->getPageTourFooter('account-heads');
    }
}
