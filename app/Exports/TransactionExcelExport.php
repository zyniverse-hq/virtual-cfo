<?php

namespace App\Exports;

use App\Models\ImportedFile;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class TransactionExcelExport implements WithMultipleSheets
{
    /** 
     * @param Builder<Transaction>|null $baseQuery
     * @param array<int, string>|null $selectedColumns
     */
    public function __construct(
        public ?string $from = null,
        public ?string $until = null,
        public ?Builder $baseQuery = null,
        public ?ImportedFile $importedFile = null,
        public ?array $selectedColumns = null,
    ) {}

    /**
     * @return array<int, TransactionDetailSheet|TransactionSummarySheet>
     */
    public function sheets(): array
    {
        return [
            new TransactionDetailSheet(from: $this->from, until: $this->until, baseQuery: $this->baseQuery, importedFile: $this->importedFile, selectedColumns: $this->selectedColumns),
            new TransactionSummarySheet(from: $this->from, until: $this->until, baseQuery: $this->baseQuery, importedFile: $this->importedFile),
        ];
    }
}
