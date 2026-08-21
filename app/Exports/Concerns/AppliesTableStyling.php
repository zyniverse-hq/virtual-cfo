<?php

namespace App\Exports\Concerns;

use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

trait AppliesTableStyling
{
    protected function applyTableStyling(Worksheet $sheet, string $tableRange, string $headerRange): void
    {
        $sheet->getStyle($tableRange)->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                'outline' => ['borderStyle' => Border::BORDER_MEDIUM],
            ],
        ]);

        $sheet->getStyle($headerRange)->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFD9E1F2'],
            ],
        ]);
    }
}
