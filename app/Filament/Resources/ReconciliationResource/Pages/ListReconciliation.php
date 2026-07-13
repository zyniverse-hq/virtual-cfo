<?php

namespace App\Filament\Resources\ReconciliationResource\Pages;

use App\Filament\Resources\ReconciliationResource;
use App\Filament\Widgets\ReconciliationStatsOverview;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListReconciliation extends ListRecords
{
    use \App\Filament\Concerns\HasPageTour;

    protected static string $resource = ReconciliationResource::class;

    public function getSubheading(): ?string
    {
        return 'Match bank transactions against invoices';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->getPageTourAction(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ReconciliationStatsOverview::class,
        ];
    }

    public function getFooter(): ?View
    {
        return $this->getPageTourFooter('reconciliation');
    }
}
