<?php

namespace App\Exports;

use App\Models\AccountHead;
use App\Models\Company;
use App\Models\ImportedFile;
use App\Models\Transaction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

class TransactionSummarySheet implements FromCollection, WithCustomStartCell, WithEvents, WithHeadings, WithTitle
{
    /** @param Builder<Transaction>|null $baseQuery */
    public function __construct(
        public ?string $from = null,
        public ?string $until = null,
        public ?Builder $baseQuery = null,
        public ?ImportedFile $importedFile = null,
    ) {}

    public function title(): string
    {
        return 'Summary';
    }

    public function startCell(): string
    {
        return $this->importedFile ? 'A5' : 'A1';
    }

    public function resolveAccountLabel(): string
    {
        if ($this->importedFile === null) {
            return '';
        }

        $file = $this->importedFile;
        $bankName = $file->bank_name ?? '';

        if ($file->credit_card_id) {
            $file->loadMissing('creditCard');
            $cardName = $file->creditCard?->name;
            $bankName = $cardName ? trim("{$bankName} {$cardName}") : $bankName;
        }

        $holderName = $file->account_holder_name;

        return $holderName ? "{$bankName} — {$holderName}" : $bankName;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Account Head',
            'Total Debit',
            'Total Credit',
            'Net Amount',
        ];
    }

    /**
     * @return Collection<int, mixed>
     */
    public function collection(): Collection
    {
        if ($this->baseQuery) {
            $query = $this->baseQuery
                ->clone()
                ->whereNotNull('account_head_id')
                ->with('accountHead');
        } else {
            /** @var Company $tenant */
            $tenant = Filament::getTenant();

            $query = Transaction::query()
                ->where('company_id', $tenant->id)
                ->whereNotNull('account_head_id')
                ->with('accountHead');
        }

        if ($this->from) {
            $query->whereDate('date', '>=', $this->from);
        }

        if ($this->until) {
            $query->whereDate('date', '<=', $this->until);
        }

        $transactions = $query->get();

        $summary = [];
        foreach ($transactions as $transaction) {
            $headId = $transaction->account_head_id;
            if ($headId === null) {
                continue;
            }

            if (! isset($summary[$headId])) {
                /** @var AccountHead|null $accountHead */
                $accountHead = $transaction->accountHead;
                $summary[$headId] = [
                    'account_head' => $accountHead?->name,
                    'total_debit' => 0.0,
                    'total_credit' => 0.0,
                    'net_amount' => 0.0,
                ];
            }

            if ($transaction->debit !== null) {
                $summary[$headId]['total_debit'] += (float) $transaction->debit;
            }
            if ($transaction->credit !== null) {
                $summary[$headId]['total_credit'] += (float) $transaction->credit;
            }
        }

        foreach ($summary as &$row) {
            $row['net_amount'] = abs($row['total_debit'] - $row['total_credit']);
        }

        return collect(array_values($summary));
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                $hasMetadata = $this->importedFile !== null;
                $headerRow = $hasMetadata ? 5 : 1;
                $dataStartRow = $hasMetadata ? 6 : 2;

                $lastDataRow = $sheet->getHighestRow();
                $totalsRow = $lastDataRow + 1;

                if ($hasMetadata) {
                    $sheet->setCellValue('A1', 'Card / Account:');
                    $sheet->setCellValue('B1', $this->resolveAccountLabel());
                    $sheet->setCellValue('A2', 'Statement Period:');
                    $sheet->setCellValue('B2', $this->importedFile->statement_period ?? '');
                    $sheet->setCellValue('A3', 'Opening Balance:');
                    $sheet->setCellValue('B3', $this->importedFile->opening_balance !== null ? (float) $this->importedFile->opening_balance : '');

                    $sheet->getStyle('A1:A3')->getFont()->setBold(true);
                    $sheet->getRowDimension(1)->setRowHeight(20);
                    $sheet->getRowDimension(2)->setRowHeight(20);
                    $sheet->getRowDimension(3)->setRowHeight(20);
                    $sheet->getRowDimension(4)->setRowHeight(20);
                }

                $sheet->getColumnDimension('A')->setWidth(32);
                $sheet->getColumnDimension('B')->setWidth(16);
                $sheet->getColumnDimension('C')->setWidth(16);
                $sheet->getColumnDimension('D')->setWidth(16);

                // Totals row
                $sheet->setCellValue("A{$totalsRow}", 'Total');
                $sheet->setCellValue("B{$totalsRow}", "=SUM(B{$dataStartRow}:B{$lastDataRow})");
                $sheet->setCellValue("C{$totalsRow}", "=SUM(C{$dataStartRow}:C{$lastDataRow})");
                $sheet->setCellValue("D{$totalsRow}", "=SUM(D{$dataStartRow}:D{$lastDataRow})");

                $sheet->getStyle("{$headerRow}:{$headerRow}")->getFont()->setBold(true);
                $sheet->getStyle("{$totalsRow}:{$totalsRow}")->getFont()->setBold(true);

                if ($hasMetadata) {
                    $closingRow = $totalsRow + 1;
                    $sheet->setCellValue("A{$closingRow}", 'Closing Balance');
                    $sheet->setCellValue("B{$closingRow}", "=B3+C{$totalsRow}-B{$totalsRow}");
                    $sheet->getStyle("{$closingRow}:{$closingRow}")->getFont()->setBold(true);
                    $sheet->getRowDimension($closingRow)->setRowHeight(20);
                }

                for ($i = $headerRow; $i <= $totalsRow; $i++) {
                    $sheet->getRowDimension($i)->setRowHeight(20);
                }
            },
        ];
    }
}
