<?php

namespace App\Filament\Resources\CashTransactions\Pages;

use App\Exports\CashTransactionExcelExport;
use App\Filament\Resources\CashTransactions\CashTransactionResource;
use App\Models\CashTransaction;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ManageRecords;
use Maatwebsite\Excel\Facades\Excel;

class ManageCashTransactions extends ManageRecords
{
    protected static string $resource = CashTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export_excel')
                ->label('Export to Excel')
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->form([
                    DatePicker::make('from')
                        ->label('From Date'),
                    DatePicker::make('until')
                        ->label('Until Date'),
                ])
                ->action(function (array $data) {
                    $query = CashTransaction::with('accountHead');

                    if (! empty($data['from'])) {
                        $query->whereDate('date', '>=', $data['from']);
                    }
                    if (! empty($data['until'])) {
                        $query->whereDate('date', '<=', $data['until']);
                    }

                    return Excel::download(
                        new CashTransactionExcelExport($query->get()),
                        'cash_transactions_'.now()->format('Y_m_d_His').'.xlsx'
                    );
                }),
            Actions\CreateAction::make(),
        ];
    }
}
