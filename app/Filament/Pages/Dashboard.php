<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasPageTour;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    use HasPageTour;

    public function getHeading(): string
    {
        return 'Welcome, '.Auth::user()->name.'!';
    }

    public function getSubheading(): ?string
    {
        return 'Here\'s an overview of your financial data.';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->getPageTourAction(),
        ];
    }

    public function getFooter(): ?View
    {
        return $this->getPageTourFooter('dashboard');
    }
}
