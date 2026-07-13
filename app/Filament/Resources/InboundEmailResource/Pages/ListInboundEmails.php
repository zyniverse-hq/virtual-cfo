<?php

namespace App\Filament\Resources\InboundEmailResource\Pages;

use App\Filament\Concerns\HasPageTour;
use App\Filament\Resources\InboundEmailResource;
use App\Filament\Widgets\MailingSystemWidget;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListInboundEmails extends ListRecords
{
    use HasPageTour;

    protected static string $resource = InboundEmailResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            MailingSystemWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->getPageTourAction(),
        ];
    }

    public function getFooter(): ?View
    {
        return $this->getPageTourFooter('inbound-emails');
    }
}
