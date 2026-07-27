<?php

namespace App\Exports;

use App\Exports\Concerns\CalculatesClosingBalance;
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
    use CalculatesClosingBalance;

    /**
     * @param  Builder<Transaction>|null  $baseQuery
     * @param  array<int, string>|null  $selectedColumns
     */
    public function __construct(
        public ?string $from = null,
        public ?string $until = null,
        public ?Builder $baseQuery = null,
        public ?ImportedFile $importedFile = null,
        public ?array $selectedColumns = null,
    ) {
        if (empty($this->selectedColumns)) {
            $this->selectedColumns = array_keys(self::availableColumns());
        } else {
            $allKeys = array_keys(self::availableColumns());
            $this->selectedColumns = array_values(array_intersect($allKeys, $this->selectedColumns));
        }
    }

    /**
     * @return array<string, string>
     */
    public static function availableColumns(): array
    {
        return [
            'date' => 'Date',
            'reference' => 'Reference',
            'account_head' => 'Account Head',
            'debit' => 'Debit',
            'credit' => 'Credit',
            'balance' => 'Balance',
            'currency' => 'Currency',
            'account_head_group' => 'Account Head Group',
            'description' => 'Description',
        ];
    }

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
        return $this->importedFile ? 'A5' : 'A1';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        $all = self::availableColumns();

        return array_values(array_intersect_key($all, array_flip($this->selectedColumns)));
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

        $fullMap = [
            'date' => $date->format('d M Y'),
            'reference' => $row->reference_number,
            'account_head' => $accountHead?->name,
            'debit' => $row->debit !== null ? (float) $row->debit : null,
            'credit' => $row->credit !== null ? (float) $row->credit : null,
            'balance' => $row->balance !== null ? (float) $row->balance : null,
            'currency' => $row->currency,
            'account_head_group' => $accountHead?->group_name,
            'description' => $row->description,
        ];

        return array_values(array_intersect_key($fullMap, array_flip($this->selectedColumns)));
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
                $this->writeCsvClosingBalance($sheet);
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
        $sheet->setCellValue('A4', 'Opening Balance:');
        $sheet->setCellValue('B4', $this->importedFile->opening_balance !== null ? (float) $this->importedFile->opening_balance : '');

        $sheet->getStyle('A1:A4')->getFont()->setBold(true);
    }

    /**
     * Append a type-aware closing balance row to the flat CSV export.
     *
     * CSV files cannot evaluate formulas, so the value is computed in PHP using
     * the same directional logic as the Excel sheets.
     */
    protected function writeCsvClosingBalance(Worksheet $sheet): void
    {
        if ($this->importedFile === null) {
            return;
        }

        $closingRow = $sheet->getHighestRow() + 1;

        $sheet->setCellValue("A{$closingRow}", 'Closing Balance');
        $sheet->setCellValue("B{$closingRow}", $this->closingBalanceValueForFile(
            $this->importedFile,
            $this->query()->get(),
        ));

        $sheet->getStyle("A{$closingRow}:B{$closingRow}")->getFont()->setBold(true);
    }
}
