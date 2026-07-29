<?php

namespace App\Filament\Resources\ImportedFileResource\Pages;

use App\Filament\Concerns\HasPageTour;
use App\Filament\Resources\ImportedFileResource;
use App\Filament\Widgets\ImportedFileStatsOverview;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListImportedFiles extends ListRecords
{
    use HasPageTour;

    protected static string $resource = ImportedFileResource::class;

    public function getSubheading(): ?string
    {
        return 'Upload and manage bank statements, credit card statements, and invoices';
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
            ImportedFileStatsOverview::class,
        ];
    }

    public function getFooter(): ?View
    {
        return $this->getPageTourFooter('imported-files');
    }
}
