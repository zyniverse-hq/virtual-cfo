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
                $sheet->setPrintGridlines(true);

                $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
                $sheet->getPageSetup()->setFitToPage(true);
                $sheet->getPageSetup()->setFitToWidth(1);
                $sheet->getPageSetup()->setFitToHeight(0);

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

                if ($lastDataRow >= $dataStartRow) {
                    $sheet->getStyle("A{$dataStartRow}:I{$lastDataRow}")
                        ->getAlignment()
                        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);

                    $sheet->getStyle("I{$dataStartRow}:I{$lastDataRow}")->getAlignment()->setWrapText(true);

                    for ($i = $dataStartRow; $i <= $lastDataRow; $i++) {
                        $cellValue = $sheet->getCell("I{$i}")->getValue();
                        if (is_string($cellValue) && strlen($cellValue) > 40) {
                            $wrapped = $this->wrapDescription($cellValue, 30);
                            $sheet->setCellValue("I{$i}", $wrapped);
                        }
                    }
                }

                $sheet->setCellValue("A{$totalsRow}", 'Total');
                $sheet->setCellValue("D{$totalsRow}", "=SUM(D{$dataStartRow}:D{$lastDataRow})");
                $sheet->setCellValue("E{$totalsRow}", "=SUM(E{$dataStartRow}:E{$lastDataRow})");

                $sheet->getStyle("{$headerRow}:{$headerRow}")->getFont()->setBold(true);
                $sheet->getStyle("{$totalsRow}:{$totalsRow}")->getFont()->setBold(true);

                for ($i = 1; $i <= $totalsRow; $i++) {
                    if ($i >= $dataStartRow && $i <= $lastDataRow) {
                        $cellValue = $sheet->getCell("I{$i}")->getValue();
                        $lineCount = is_string($cellValue) ? substr_count($cellValue, "\n") + 1 : 1;
                        $sheet->getRowDimension($i)->setRowHeight(max(20, $lineCount * 15));
                    } else {
                        $sheet->getRowDimension($i)->setRowHeight(20);
                    }
                }
            },
        ];
    }

    /**
     * Wrap description text at slashes and spaces to avoid large horizontal gaps.
     */
    private function wrapDescription(string $text, int $limit = 30): string
    {
        if (strlen($text) <= $limit) {
            return $text;
        }

        $words = preg_split('/([\/ ])/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($words === false) {
            return $text;
        }

        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            if (strlen($currentLine) + strlen($word) > $limit) {
                if ($currentLine !== '') {
                    $lines[] = rtrim($currentLine);
                    $currentLine = '';
                }

                if (strlen($word) > $limit) {
                    $chunks = str_split($word, $limit);
                    $lastChunk = array_pop($chunks);
                    foreach ($chunks as $chunk) {
                        $lines[] = $chunk;
                    }
                    $currentLine = $lastChunk;
                } else {
                    $currentLine = ltrim($word);
                }
            } else {
                $currentLine .= $word;
            }
        }

        if ($currentLine !== '') {
            $lines[] = rtrim($currentLine);
        }

        return implode("\n", $lines);
    }
}
