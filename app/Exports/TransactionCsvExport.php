<?php

namespace App\Exports;

use App\Models\AccountHead;
use App\Models\Company;
use App\Models\ImportedFile;
use App\Models\Transaction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * @implements WithMapping<Transaction>
 */
class TransactionCsvExport implements FromQuery, WithCustomStartCell, WithEvents, WithHeadings, WithMapping
{
    /** @param Builder<Transaction>|null $baseQuery */
    public function __construct(
        public ?string $from = null,
        public ?string $until = null,
        public ?Builder $baseQuery = null,
        public ?ImportedFile $importedFile = null,
    ) {}

    /**
     * @return Builder<Transaction>
     */
    public function query(): Builder
    {
        if ($this->baseQuery) {
            $query = $this->baseQuery
                ->clone()
                ->whereNotNull('account_head_id')
                ->with(['accountHead'])
                ->orderBy('date');
        } else {
            /** @var Company $tenant */
            $tenant = Filament::getTenant();

            $query = Transaction::query()
                ->where('company_id', $tenant->id)
                ->whereNotNull('account_head_id')
                ->with(['accountHead'])
                ->orderBy('date');
        }

        if ($this->from) {
            $query->whereDate('date', '>=', $this->from);
        }

        if ($this->until) {
            $query->whereDate('date', '<=', $this->until);
        }

        return $query;
    }

    public function startCell(): string
    {
        return $this->importedFile ? 'A4' : 'A1';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Date',
            'Reference',
            'Account Head',
            'Debit',
            'Credit',
            'Balance',
            'Currency',
            'Account Head Group',
            'Description',
        ];
    }

    /**
     * @param  Transaction  $row
     * @return array<int, string|float|null>
     */
    public function map($row): array
    {
        /** @var Carbon $date */
        $date = $row->date;
        /** @var AccountHead|null $accountHead */
        $accountHead = $row->accountHead;

        return [
            $date->format('d M Y'),
            $row->reference_number,
            $accountHead?->name,
            $row->debit !== null ? (float) $row->debit : null,
            $row->credit !== null ? (float) $row->credit : null,
            $row->balance !== null ? (float) $row->balance : null,
            $row->currency,
            $accountHead?->group_name,
            $row->description,
        ];
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $this->writeTransactionsMetadata($event->sheet->getDelegate());
            },
        ];
    }

    protected function writeTransactionsMetadata(Worksheet $sheet): void
    {
        if ($this->importedFile === null) {
            return;
        }

        $sheet->setCellValue('A1', 'Bank:');
        $sheet->setCellValue('B1', $this->importedFile->bank_name ?? '');
        $sheet->setCellValue('A2', 'Account Holder:');
        $sheet->setCellValue('B2', $this->importedFile->account_holder_name ?? '');
        $sheet->setCellValue('A3', 'Statement Period:');
        $sheet->setCellValue('B3', $this->importedFile->statement_period ?? '');

        $sheet->getStyle('A1:A3')->getFont()->setBold(true);
    }
}
