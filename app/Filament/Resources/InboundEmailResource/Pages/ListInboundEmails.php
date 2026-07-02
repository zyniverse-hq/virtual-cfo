<?php

namespace App\Filament\Resources\InboundEmailResource\Pages;

use App\Filament\Resources\InboundEmailResource;
use Filament\Resources\Pages\ListRecords;

class ListInboundEmails extends ListRecords
{
    use \App\Filament\Concerns\HasPageTour;

    protected static string $resource = InboundEmailResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->getPageTourAction(),
        ];
    }

    public function getFooter(): ?\Illuminate\Contracts\View\View
    {
        return $this->getPageTourFooter('inbound-emails');
    }
}
