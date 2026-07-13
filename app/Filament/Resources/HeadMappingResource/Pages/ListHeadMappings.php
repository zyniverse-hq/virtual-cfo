<?php

namespace App\Filament\Resources\HeadMappingResource\Pages;

use App\Filament\Resources\HeadMappingResource;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListHeadMappings extends ListRecords
{
    use \App\Filament\Concerns\HasPageTour;

    protected static string $resource = HeadMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->icon('heroicon-o-plus')
                ->extraAttributes(['class' => 'tour-create-rule']),
            $this->getPageTourAction(),
        ];
    }

    public function getFooter(): ?View
    {
        return $this->getPageTourFooter('head-mappings');
    }
}
