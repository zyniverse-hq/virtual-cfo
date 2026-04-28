<?php

namespace App\Filament\Resources;

use App\Enums\ImportStatus;
use App\Enums\MatchMethod;
use App\Enums\MatchStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\StatementType;
use App\Filament\Resources\ReconciliationResource\Pages;
use App\Jobs\ReconcileImportedFiles;
use App\Models\Company;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\TallyExport\TallyExportService;
use BackedEnum;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

class ReconciliationResource extends Resource
{
    use Concerns\HasTransactionColumns;

    protected static ?string $model = Transaction::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Reconciliation';

    protected static ?string $slug = 'reconciliation';

    protected static ?int $navigationSort = 5;

    /** @return Builder<Transaction> */
    public static function getEloquentQuery(): Builder
    {
        return Transaction::query()
            ->with([
                'importedFile',
                'accountHead',
                'reconciliationMatchesAsBank' => fn (Relation $q) => $q->whereIn('status', [MatchStatus::Suggested, MatchStatus::Confirmed])->with('invoiceTransaction'),
            ])
            ->whereHas('importedFile', fn (Builder $q) => $q->whereIn('statement_type', [StatementType::Bank, StatementType::CreditCard]));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(40)
                    ->tooltip(fn (Transaction $record): string => $record->description)
                    ->searchable()
                    ->description(function (Transaction $record): ?string {
                        $invoiceTxn = $record->reconciliationMatchesAsBank->first()?->invoiceTransaction;

                        if (! $invoiceTxn) {
                            return null;
                        }

                        /** @var array<string, mixed> $raw */
                        $raw = $invoiceTxn->raw_data ?? [];

                        return self::formatMatchedInvoiceLabel($raw, $invoiceTxn->description);
                    }),

                static::amountColumn(),

                Tables\Columns\TextColumn::make('reconciliation_status')
                    ->label('Status')
                    ->badge(),

                Tables\Columns\TextColumn::make('accountHead.name')
                    ->label('Account Head')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable()
                    ->description(static::mappingTypeDescription()),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('reconciliation_status')
                    ->options(ReconciliationStatus::class),
            ])
            ->actions([
                Actions\Action::make('confirm_suggestion')
                    ->label('Confirm')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->action(function (Transaction $record) {
                        $match = $record->reconciliationMatchesAsBank()->suggested()->firstOrFail();

                        app(ReconciliationService::class)->confirmSuggestion($match);

                        Notification::make()
                            ->title('Suggestion confirmed')
                            ->success()
                            ->send();
                    })
                    ->visible(fn (Transaction $record) => $record->reconciliationMatchesAsBank->where('status', MatchStatus::Suggested)->isNotEmpty()),

                Actions\Action::make('reject_suggestions')
                    ->label('Reject All')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Transaction $record) {
                        app(ReconciliationService::class)->rejectAllSuggestions($record);

                        Notification::make()
                            ->title('All suggestions rejected')
                            ->warning()
                            ->send();
                    })
                    ->visible(fn (Transaction $record) => $record->reconciliationMatchesAsBank->where('status', MatchStatus::Suggested)->isNotEmpty()),

                Actions\ActionGroup::make([
                    Actions\Action::make('manual_match')
                        ->label('Match Invoice')
                        ->icon('heroicon-o-link')
                        ->color('warning')
                        ->form([
                            Select::make('invoice_transaction_id')
                                ->label('Invoice Transaction')
                                ->options(function (Transaction $record) {
                                    return Transaction::whereHas('importedFile', fn (Builder $q) => $q->where('statement_type', StatementType::Invoice)
                                        ->where('company_id', $record->importedFile?->company_id))
                                        ->whereIn('reconciliation_status', [ReconciliationStatus::Unreconciled, ReconciliationStatus::Flagged])
                                        ->orderByDesc('date')
                                        ->limit(500)
                                        ->get()
                                        ->mapWithKeys(fn (Transaction $t) => [
                                            $t->id => Carbon::parse($t->date)->format('d M Y').' - '.$t->description.' ('.number_format((float) $t->debit, 2).')',
                                        ]);
                                })
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (Transaction $record, array $data) {
                            $invoiceTxn = Transaction::findOrFail($data['invoice_transaction_id']);

                            $service = app(ReconciliationService::class);
                            $service->createMatch($record, $invoiceTxn, 1.0, MatchMethod::Manual);
                            $service->enrichMatchedTransactions($record->importedFile);

                            Notification::make()
                                ->title('Invoice matched successfully')
                                ->success()
                                ->send();
                        })
                        ->visible(fn (Transaction $record) => $record->reconciliation_status !== ReconciliationStatus::Matched),
                ]),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('bulk_confirm')
                        ->label('Confirm Selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $service = app(ReconciliationService::class);

                            /** @var Collection<int, Transaction> $records */
                            $records->each(function (Transaction $record) use ($service): void {
                                $match = $record->reconciliationMatchesAsBank->first();

                                if (! $match) {
                                    return;
                                }

                                $service->confirmSuggestion($match);
                            });

                            $records
                                ->pluck('importedFile')
                                ->filter()
                                ->unique('id')
                                ->each(fn (ImportedFile $file) => $service->enrichMatchedTransactions($file));
                        })
                        ->deselectRecordsAfterCompletion(),

                    Actions\BulkAction::make('bulk_reject')
                        ->label('Reject Selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $service = app(ReconciliationService::class);

                            /** @var Collection<int, Transaction> $records */
                            $records->each(fn (Transaction $record) => $service->rejectAllSuggestions($record));
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->headerActions([
                Actions\Action::make('export_tally')
                    ->label('Export to Tally')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function () {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        $transactions = Transaction::query()
                            ->where('company_id', $company->id)
                            ->matched()
                            ->whereNotNull('account_head_id')
                            ->whereHas('importedFile', fn (Builder $q) => $q->whereIn('statement_type', [StatementType::Bank, StatementType::CreditCard]))
                            ->with(['accountHead', 'importedFile.bankAccount', 'importedFile.company'])
                            ->orderBy('date')
                            ->get();

                        if ($transactions->isEmpty()) {
                            Notification::make()
                                ->title('No matched transactions to export')
                                ->warning()
                                ->send();

                            return;
                        }

                        $xml = app(TallyExportService::class)->exportTransactions($transactions);

                        return response()->streamDownload(
                            fn () => print ($xml),
                            'tally-export-'.now()->format('Y-m-d-His').'.xml',
                            ['Content-Type' => 'application/xml'],
                        );
                    }),

                Actions\Action::make('run_reconciliation')
                    ->label('Run Reconciliation')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->form([
                        Select::make('bank_file_id')
                            ->label('Bank / CC Statement File')
                            ->options(function () {
                                /** @var Company $company */
                                $company = Filament::getTenant();

                                return ImportedFile::where('company_id', $company->id)
                                    ->whereIn('statement_type', [StatementType::Bank, StatementType::CreditCard])
                                    ->where('status', ImportStatus::Completed)
                                    ->get(['id', 'display_name', 'original_filename'])
                                    ->mapWithKeys(fn (ImportedFile $f) => [
                                        $f->id => $f->display_name ?? $f->original_filename,
                                    ]);
                            })
                            ->searchable()
                            ->required(),

                        Select::make('invoice_file_id')
                            ->label('Invoice File')
                            ->options(function () {
                                /** @var Company $company */
                                $company = Filament::getTenant();

                                return ImportedFile::where('company_id', $company->id)
                                    ->where('statement_type', StatementType::Invoice)
                                    ->where('status', ImportStatus::Completed)
                                    ->get(['id', 'display_name', 'original_filename'])
                                    ->mapWithKeys(fn (ImportedFile $f) => [
                                        $f->id => $f->display_name ?? $f->original_filename,
                                    ]);
                            })
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        /** @var ImportedFile $bankFile */
                        $bankFile = ImportedFile::findOrFail($data['bank_file_id']);
                        /** @var ImportedFile $invoiceFile */
                        $invoiceFile = ImportedFile::findOrFail($data['invoice_file_id']);

                        ReconcileImportedFiles::dispatch($bankFile, $invoiceFile);

                        Notification::make()
                            ->title('Reconciliation job dispatched')
                            ->body("Matching {$bankFile->original_filename} against {$invoiceFile->original_filename}")
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('No transactions to reconcile')
            ->emptyStateDescription('Upload bank statements and invoices, then run reconciliation to match them.')
            ->emptyStateIcon('heroicon-o-scale');
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function formatMatchedInvoiceLabel(array $raw, ?string $fallbackDescription): ?string
    {
        $vendor = $raw['vendor_name'] ?? '';
        $invoiceNumber = $raw['invoice_number'] ?? '';

        if (blank($vendor) && blank($invoiceNumber)) {
            $vendor = $fallbackDescription ?? '';
        }

        if (blank($vendor) && blank($invoiceNumber)) {
            return null;
        }

        return $invoiceNumber !== ''
            ? "↳ {$vendor} · #{$invoiceNumber}"
            : "↳ {$vendor}";
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReconciliation::route('/'),
        ];
    }
}
