<?php

namespace App\Filament\Widgets;

use App\Enums\InboundEmailStatus;
use App\Filament\Resources\InboundEmailResource;
use App\Models\Company;
use App\Models\InboundEmail;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class MailingSystemWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return auth()->user()->currentRole()?->canManageTeam() ?? false;
    }

    protected function getStats(): array
    {
        /** @var Company|null $tenant */
        $tenant = Filament::getTenant();
        $tenantId = $tenant?->id;

        if (! $tenantId) {
            return [];
        }

        $baseQuery = InboundEmail::where('company_id', $tenantId);

        $recentEmails = (clone $baseQuery)
            ->where('received_at', '>=', Carbon::now()->subDays(7))
            ->count();

        $rejectedEmails = (clone $baseQuery)
            ->where('status', InboundEmailStatus::Rejected)
            ->count();

        $noAttachments = (clone $baseQuery)
            ->where('status', InboundEmailStatus::NoAttachments)
            ->count();

        $url = InboundEmailResource::getUrl('index');

        return [
            Stat::make('Emails Received (7 Days)', $recentEmails)
                ->description('Inbound statements')
                ->icon('heroicon-o-envelope')
                ->color('primary')
                ->url($url),

            Stat::make('Rejected', $rejectedEmails)
                ->description('Failed processing')
                ->icon('heroicon-o-x-circle')
                ->color($rejectedEmails > 0 ? 'danger' : 'success')
                ->url($url),

            Stat::make('No Attachments', $noAttachments)
                ->description('Emails with no files')
                ->icon('heroicon-o-paper-clip')
                ->color($noAttachments > 0 ? 'warning' : 'success')
                ->url($url),
        ];
    }
}
