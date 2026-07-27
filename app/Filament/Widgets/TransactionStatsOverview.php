<?php

namespace App\Filament\Widgets;

use App\Enums\MappingType;
use App\Models\Transaction;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TransactionStatsOverview extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $total = Transaction::count();
        $unmapped = Transaction::where('mapping_type', MappingType::Unmapped)->count();
        $mapped = $total - $unmapped;
        $mappedPercentage = $total > 0
            ? round(($mapped / $total) * 100, 1)
            : 0;

        return [
            Stat::make('Total Transactions', number_format($total))
                ->icon('heroicon-o-banknotes')
                ->color('primary'),

            Stat::make('Unmapped', number_format($unmapped))
                ->description('Transactions needing attention')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($unmapped > 0 ? 'warning' : 'success'),

            Stat::make('Mapped', "{$mappedPercentage}%")
                ->description("{$mapped} transactions mapped")
                ->icon('heroicon-o-check-circle')
                ->color($mappedPercentage >= 80 ? 'success' : 'warning')
                ->extraAttributes(['class' => 'tour-mapping-stats']),
        ];
    }
}
