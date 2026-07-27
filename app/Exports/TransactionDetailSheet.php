<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

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

                $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
                $sheet->getPageSetup()->setFitToPage(true);
                $sheet->getPageSetup()->setFitToWidth(1);
                $sheet->getPageSetup()->setFitToHeight(0);

                $this->writeTransactionsMetadata($sheet);

                $hasMetadata = $this->importedFile !== null;
                $headerRow = $hasMetadata ? 4 : 1;
                $dataStartRow = $hasMetadata ? 5 : 2;

                $lastDataRow = $sheet->getHighestRow();
                $totalsRow = $lastDataRow + 1;

                $columnWidths = [
                    'date' => 14,
                    'reference' => 18,
                    'account_head' => 28,
                    'debit' => 15,
                    'credit' => 15,
                    'balance' => 15,
                    'currency' => 12,
                    'account_head_group' => 22,
                    'description' => 45,
                ];

                $colLetters = [];
                $colIndex = 1;
                foreach ($this->selectedColumns as $colName) {
                    $letter = Coordinate::stringFromColumnIndex($colIndex);
                    $colLetters[$colName] = $letter;
                    if (isset($columnWidths[$colName])) {
                        $sheet->getColumnDimension($letter)->setWidth($columnWidths[$colName]);
                    }
                    $colIndex++;
                }

                $lastColLetter = Coordinate::stringFromColumnIndex(count($this->selectedColumns));

                if ($lastDataRow >= $dataStartRow) {
                    $sheet->getStyle("A{$dataStartRow}:{$lastColLetter}{$lastDataRow}")
                        ->getAlignment()
                        ->setVertical(Alignment::VERTICAL_TOP);

                    if (isset($colLetters['description'])) {
                        $descCol = $colLetters['description'];
                        $sheet->getStyle("{$descCol}{$dataStartRow}:{$descCol}{$lastDataRow}")->getAlignment()->setWrapText(true);

                        for ($i = $dataStartRow; $i <= $lastDataRow; $i++) {
                            $cellValue = $sheet->getCell("{$descCol}{$i}")->getValue();
                            if (is_string($cellValue) && strlen($cellValue) > 40) {
                                $wrapped = $this->wrapDescription($cellValue, 30);
                                $sheet->setCellValue("{$descCol}{$i}", $wrapped);
                            }
                        }
                    }
                }

                $sheet->setCellValue("A{$totalsRow}", 'Total');

                if (isset($colLetters['debit'])) {
                    $debitCol = $colLetters['debit'];
                    $sheet->setCellValue("{$debitCol}{$totalsRow}", "=SUM({$debitCol}{$dataStartRow}:{$debitCol}{$lastDataRow})");
                }
                if (isset($colLetters['credit'])) {
                    $creditCol = $colLetters['credit'];
                    $sheet->setCellValue("{$creditCol}{$totalsRow}", "=SUM({$creditCol}{$dataStartRow}:{$creditCol}{$lastDataRow})");
                }

                $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$headerRow}")->getFont()->setBold(true);
                $sheet->getStyle("A{$totalsRow}:{$lastColLetter}{$totalsRow}")->getFont()->setBold(true);

                for ($i = 1; $i <= $totalsRow; $i++) {
                    if ($i >= $dataStartRow && $i <= $lastDataRow && isset($colLetters['description'])) {
                        $descCol = $colLetters['description'];
                        $cellValue = $sheet->getCell("{$descCol}{$i}")->getValue();
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
