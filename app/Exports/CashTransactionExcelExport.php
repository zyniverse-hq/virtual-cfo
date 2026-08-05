<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CashTransactionExcelExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    /**
     * @var Collection<int, CashTransaction>
     */
    protected Collection $records;

    /**
     * @param Collection<int, CashTransaction> $records
     */
    public function __construct(Collection $records)
    {
        $this->records = $records;
    }

    /**
     * @return Collection<int, mixed>
     */
    public function collection(): Collection
    {
        $data = $this->records->map(function ($transaction) {
            return [
                $transaction->date,
                $transaction->description,
                $transaction->amount,
                $transaction->accountHead?->name,
            ];
        });

        $totalAmount = $this->records->sum('amount');

        $data->push([
            '',
            'Total',
            $totalAmount,
            '',
        ]);

        return $data;
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Date',
            'Description',
            'Amount',
            'Account Head',
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    public function styles(Worksheet $sheet): array
    {
        $lastRow = $sheet->getHighestRow();
        $lastCol = $sheet->getHighestColumn();
        $range = 'A1:'.$lastCol.$lastRow;

        return [
            1 => ['font' => ['bold' => true]],
            $range => [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                    ],
                ],
            ],
        ];
    }
}
