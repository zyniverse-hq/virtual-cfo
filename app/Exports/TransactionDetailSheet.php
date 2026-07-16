<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

class TransactionDetailSheet extends TransactionCsvExport implements WithTitle
{
    public function title(): string
    {
        return 'Transactions';
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                $this->writeTransactionsMetadata($sheet);

                $hasMetadata = $this->importedFile !== null;
                $headerRow = $hasMetadata ? 4 : 1;
                $dataStartRow = $hasMetadata ? 5 : 2;

                $lastDataRow = $sheet->getHighestRow();
                $totalsRow = $lastDataRow + 1;

                $sheet->getColumnDimension('A')->setWidth(14);
                $sheet->getColumnDimension('B')->setWidth(18);
                $sheet->getColumnDimension('C')->setWidth(28);
                $sheet->getColumnDimension('D')->setWidth(15);
                $sheet->getColumnDimension('E')->setWidth(15);
                $sheet->getColumnDimension('F')->setWidth(15);
                $sheet->getColumnDimension('G')->setWidth(12);
                $sheet->getColumnDimension('H')->setWidth(22);
                $sheet->getColumnDimension('I')->setWidth(45);

                $sheet->setCellValue("A{$totalsRow}", 'Total');
                $sheet->setCellValue("D{$totalsRow}", "=SUM(D{$dataStartRow}:D{$lastDataRow})");
                $sheet->setCellValue("E{$totalsRow}", "=SUM(E{$dataStartRow}:E{$lastDataRow})");

                $sheet->getStyle("{$headerRow}:{$headerRow}")->getFont()->setBold(true);
                $sheet->getStyle("{$totalsRow}:{$totalsRow}")->getFont()->setBold(true);

                for ($i = 1; $i <= $totalsRow; $i++) {
                    $sheet->getRowDimension($i)->setRowHeight(20);
                }
            },
        ];
    }
}
