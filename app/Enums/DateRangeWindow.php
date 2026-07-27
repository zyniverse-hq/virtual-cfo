<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Carbon;

enum DateRangeWindow: string implements HasLabel
{
    case PreviousDay = 'previous_day';
    case Previous7Days = 'previous_7_days';
    case Previous14Days = 'previous_14_days';
    case Previous30Days = 'previous_30_days';
    case PreviousMonth = 'previous_month';
    case PreviousQuarter = 'previous_quarter';

    public function getLabel(): string
    {
        return match ($this) {
            self::PreviousDay => 'Previous Day',
            self::Previous7Days => 'Previous 7 Days',
            self::Previous14Days => 'Previous 14 Days',
            self::Previous30Days => 'Previous 30 Days',
            self::PreviousMonth => 'Previous Month',
            self::PreviousQuarter => 'Previous Quarter',
        };
    }

    /**
     * Resolve the date range relative to the given reference date.
     *
     * @return array{Carbon, Carbon}
     */
    public function toDateRange(?Carbon $referenceDate = null): array
    {
        $now = $referenceDate ?? Carbon::now();

        return match ($this) {
            self::PreviousDay => [
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ],
            self::Previous7Days => [
                $now->copy()->subDays(7)->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ],
            self::Previous14Days => [
                $now->copy()->subDays(14)->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ],
            self::Previous30Days => [
                $now->copy()->subDays(30)->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ],
            self::PreviousMonth => [
                $now->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay(),
                $now->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay(),
            ],
            self::PreviousQuarter => [
                $now->copy()->subQuarterNoOverflow()->startOfQuarter()->startOfDay(),
                $now->copy()->subQuarterNoOverflow()->endOfQuarter()->endOfDay(),
            ],
        };
    }
}
