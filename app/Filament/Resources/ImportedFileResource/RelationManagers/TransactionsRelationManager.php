<?php

namespace App\Filament\Resources\ImportedFileResource\RelationManagers;

use App\Enums\MappingType;
use App\Enums\MatchMethod;
use App\Enums\MatchType;
use App\Enums\ReconciliationStatus;
use App\Enums\StatementType;
use App\Enums\UserRole;
use App\Exports\TransactionCsvExport;
use App\Exports\TransactionExcelExport;
use App\Filament\Resources\Concerns\HasTransactionColumns;
use App\Jobs\MatchTransactionHeads;
use App\Models\AccountHead;
use App\Models\Company;
use App\Models\CreditCard;
use App\Models\HeadMapping;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\RuleSuggestion\RuleSuggestionService;
use App\Services\TallyExport\TallyExportService;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionsRelationManager extends RelationManager
{
    use HasTransactionColumns;

    protected static string $relationship = 'transactions';

    public function table(Table $table): Table
    {
        return $table
            ->poll(function (): string {
                /** @var ImportedFile $file */
                $file = $this->getOwnerRecord();

                return $file->isProcessing() ? '10s' : '30s';
            })
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->limit(50)
                    ->tooltip(fn (Transaction $record) => $record->description),

                Tables\Columns\TextColumn::make('reference_number')
                    ->label('Ref #')
                    ->toggleable(isToggledHiddenByDefault: true),

                static::amountColumn(),

                static::currencyColumn(),

                Tables\Columns\TextColumn::make('balance')
                    ->label('Balance')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('accountHead.name')
                    ->label('Account Head')
                    ->placeholder('Unmapped')
                    ->searchable()
                    ->description(static::mappingTypeDescription()),

                Tables\Columns\IconColumn::make('recurring_pattern_id')
                    ->label('Recurring')
                    ->icon(fn ($state) => $state ? 'heroicon-o-arrow-path' : null)
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('mapping_type')
                    ->options(MappingType::class),

                Tables\Filters\SelectFilter::make('account_head_id')
                    ->label('Account Head')
                    ->relationship('accountHead', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('date_range')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q, string $date) => $q->whereDate('date', '>=', $date))
                            ->when($data['until'], fn (Builder $q, string $date) => $q->whereDate('date', '<=', $date));
                    }),

                Tables\Filters\TernaryFilter::make('unmapped_only')
                    ->label('Unmapped Only')
                    ->queries(
                        true: fn (Builder $query) => $query->where('mapping_type', MappingType::Unmapped),
                        false: fn (Builder $query) => $query->where('mapping_type', '!=', MappingType::Unmapped),
                    ),
            ])
            ->recordActions([
                Action::make('assign_head')
                    ->label('Assign Head')
                    ->icon('heroicon-o-tag')
                    ->form([
                        Forms\Components\Select::make('account_head_id')
                            ->label('Account Head')
                            ->options(fn () => AccountHead::where('is_active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Transaction $record, array $data) {
                        DB::transaction(function () use ($record, $data) {
                            $record->update([
                                'account_head_id' => $data['account_head_id'],
                                'mapping_type' => MappingType::Manual,
                                'ai_confidence' => null,
                            ]);

                            $file = $record->importedFile;
                            $file->update([
                                'mapped_rows' => $file->transactions()
                                    ->where('mapping_type', '!=', MappingType::Unmapped)
                                    ->count(),
                            ]);
                        });

                        $record->refresh();
                        $this->sendRuleSuggestionNotification($record);
                    }),

                Actions\ActionGroup::make([
                    Action::make('create_rule')
                        ->label('Create Rule')
                        ->icon('heroicon-o-plus-circle')
                        ->color('info')
                        ->form([
                            Forms\Components\TextInput::make('pattern')
                                ->label('Pattern')
                                ->required()
                                ->default(fn (Transaction $record) => $record->description),

                            Forms\Components\Select::make('match_type')
                                ->options(MatchType::class)
                                ->default(MatchType::Contains)
                                ->required(),

                            Forms\Components\Select::make('account_head_id')
                                ->label('Account Head')
                                ->options(fn () => AccountHead::where('is_active', true)->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                                ->default(fn (Transaction $record) => $record->account_head_id),

                            Forms\Components\TextInput::make('bank_name')
                                ->label('Bank Name (optional)')
                                ->default(fn (Transaction $record) => $record->importedFile?->bank_name),
                        ])
                        ->action(function (Transaction $record, array $data) {
                            /** @var Company|null $tenant */
                            $tenant = Filament::getTenant();

                            try {
                                HeadMapping::create([
                                    'pattern' => $data['pattern'],
                                    'match_type' => $data['match_type'],
                                    'account_head_id' => $data['account_head_id'],
                                    'bank_name' => $data['bank_name'] ?: null,
                                    'company_id' => $tenant?->id,
                                    'created_by' => Auth::id(),
                                ]);
                            } catch (UniqueConstraintViolationException) {
                                Notification::make()
                                    ->danger()
                                    ->title('Duplicate rule')
                                    ->body('A mapping rule with this pattern, match type, and account head already exists.')
                                    ->send();

                                throw new Halt;
                            }

                            Notification::make()
                                ->title('Mapping rule created')
                                ->success()
                                ->send();
                        })
                        ->visible(fn (Transaction $record) => $record->account_head_id !== null),

                    Action::make('match_invoice')
                        ->label('Match Invoice')
                        ->icon('heroicon-o-link')
                        ->color('warning')
                        ->form([
                            Forms\Components\CheckboxList::make('invoice_transaction_ids')
                                ->label('Select Invoice(s)')
                                ->options(function (Transaction $record) {
                                    return Transaction::whereHas(
                                        'importedFile',
                                        fn (Builder $q) => $q->where('statement_type', StatementType::Invoice)
                                            ->where('company_id', $record->importedFile?->company_id)
                                    )
                                        ->where('reconciliation_status', ReconciliationStatus::Unreconciled)
                                        ->select(['id', 'description', 'debit'])
                                        ->orderByDesc('date')
                                        ->limit(500)
                                        ->get()
                                        ->mapWithKeys(fn (Transaction $t) => [
                                            $t->id => $t->description.' ('.number_format((float) $t->debit, 2).')',
                                        ]);
                                })
                                ->required(),
                        ])
                        ->action(function (Transaction $record, array $data) {
                            $service = app(ReconciliationService::class);
                            $invoiceTransactions = Transaction::whereIn('id', $data['invoice_transaction_ids'])->get()->keyBy('id');

                            DB::transaction(function () use ($record, $data, $service, $invoiceTransactions) {
                                foreach ($data['invoice_transaction_ids'] as $invoiceId) {
                                    /** @var Transaction $invoiceTxn */
                                    $invoiceTxn = $invoiceTransactions->get($invoiceId);
                                    $service->createMatch($record, $invoiceTxn, 1.0, MatchMethod::Manual);
                                }
                            });

                            $record->loadMissing('importedFile');
                            $service->enrichMatchedTransactions($record->importedFile);

                            Notification::make()
                                ->title('Invoice matched successfully')
                                ->success()
                                ->send();
                        })
                        ->visible(fn (Transaction $record) => $record->reconciliation_status === ReconciliationStatus::Unreconciled
                            && $record->importedFile?->statement_type !== StatementType::Invoice),
                ]),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('bulk_assign_head')
                        ->label('Assign Account Head')
                        ->icon('heroicon-o-tag')
                        ->form([
                            Forms\Components\Select::make('account_head_id')
                                ->label('Account Head')
                                ->options(fn () => AccountHead::where('is_active', true)->pluck('name', 'id'))
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            DB::transaction(function () use ($records, $data) {
                                $records->each(function (Model $record) use ($data) {
                                    $record->update([
                                        'account_head_id' => $data['account_head_id'],
                                        'mapping_type' => MappingType::Manual,
                                        'ai_confidence' => null,
                                    ]);
                                });

                                /** @var ImportedFile $file */
                                $file = $this->getOwnerRecord();
                                $file->update([
                                    'mapped_rows' => $file->transactions()
                                        ->where('mapping_type', '!=', MappingType::Unmapped)
                                        ->count(),
                                ]);
                            });

                            /** @var Transaction|null $first */
                            $first = $records->first();
                            if ($first) {
                                $first->refresh();
                                $this->sendRuleSuggestionNotification($first);
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    Actions\BulkAction::make('move_to_company')
                        ->label('Move to Company')
                        ->icon('heroicon-o-arrow-right-circle')
                        ->color('warning')
                        ->form([
                            Forms\Components\Select::make('target_company_id')
                                ->label('Target Company')
                                ->options(function () {
                                    $user = Auth::user();
                                    /** @var Company|null $currentTenant */
                                    $currentTenant = Filament::getTenant();

                                    return Company::query()
                                        ->whereHas('users', function (Builder $q) use ($user) {
                                            $q->where('users.id', $user->id)
                                                ->where('company_user.role', UserRole::Admin->value);
                                        })
                                        ->when($currentTenant, fn (Builder $q) => $q->where('companies.id', '!=', $currentTenant->id))
                                        ->pluck('name', 'id');
                                })
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $targetCompany = Company::find($data['target_company_id']);

                            $creditCardIds = $records->pluck('imported_file_id')
                                ->map(fn ($fileId) => ImportedFile::find($fileId)?->credit_card_id)
                                ->filter()
                                ->unique();

                            $allShared = $creditCardIds->every(function ($cardId) use ($targetCompany) {
                                $card = CreditCard::find($cardId);

                                return $card && $card->isSharedWith($targetCompany);
                            });

                            if (! $allShared) {
                                Notification::make()
                                    ->title('Card must be shared with the target company first')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $count = 0;
                            $records->each(function (Model $record) use ($targetCompany, &$count) {
                                /** @var Transaction $tx */
                                $tx = $record;
                                $tx->moveToCompany($targetCompany);
                                $count++;
                            });

                            Notification::make()
                                ->title($count.' transactions moved to '.$targetCompany->name)
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->headerActions([
                Action::make('run_ai_matching')
                    ->label('Run AI Matching')
                    ->icon('heroicon-o-cpu-chip')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('This will run rule-based and AI matching on all unmapped transactions in this file.')
                    ->action(function () {
                        /** @var ImportedFile $file */
                        $file = $this->getOwnerRecord();

                        if ($file->is_matching) {
                            return;
                        }

                        $file->update(['is_matching' => true]);
                        MatchTransactionHeads::dispatch($file);

                        Notification::make()
                            ->title('AI matching job dispatched')
                            ->success()
                            ->send();
                    }),

                Actions\ActionGroup::make([
                    Action::make('export_tally')
                        ->label('Tally XML')
                        ->icon('heroicon-o-document-text')
                        ->form([
                            Forms\Components\DatePicker::make('from')->label('From Date'),
                            Forms\Components\DatePicker::make('until')->label('Until Date'),
                        ])
                        ->action(function (array $data): StreamedResponse {
                            /** @var ImportedFile $file */
                            $file = $this->getOwnerRecord();

                            $query = Transaction::where('imported_file_id', $file->id)
                                ->whereNotNull('account_head_id')
                                ->with(['accountHead', 'importedFile.company', 'importedFile.bankAccount'])
                                ->orderBy('date');

                            if (! empty($data['from'])) {
                                $query->whereDate('date', '>=', $data['from']);
                            }

                            if (! empty($data['until'])) {
                                $query->whereDate('date', '<=', $data['until']);
                            }

                            $xml = app(TallyExportService::class)->exportTransactions($query->get());

                            return response()->streamDownload(
                                fn () => print ($xml),
                                'tally-export-'.now()->format('Y-m-d-His').'.xml',
                                ['Content-Type' => 'application/xml']
                            );
                        }),

                    Action::make('export_csv')
                        ->label('CSV')
                        ->icon('heroicon-o-table-cells')
                        ->form([
                            Forms\Components\DatePicker::make('from')->label('From Date'),
                            Forms\Components\DatePicker::make('until')->label('Until Date'),
                        ])
                        ->action(function (array $data): BinaryFileResponse {
                            /** @var ImportedFile $file */
                            $file = $this->getOwnerRecord();

                            return Excel::download(
                                new TransactionCsvExport(
                                    from: $data['from'] ?? null,
                                    until: $data['until'] ?? null,
                                    baseQuery: Transaction::where('imported_file_id', $file->id),
                                    importedFile: $file,
                                ),
                                'transactions-'.now()->format('Y-m-d-His').'.csv',
                            );
                        }),

                    Action::make('export_excel')
                        ->label('Excel')
                        ->icon('heroicon-o-document-arrow-down')
                        ->form([
                            Forms\Components\DatePicker::make('from')->label('From Date'),
                            Forms\Components\DatePicker::make('until')->label('Until Date'),
                        ])
                        ->action(function (array $data): BinaryFileResponse {
                            /** @var ImportedFile $file */
                            $file = $this->getOwnerRecord();

                            return Excel::download(
                                new TransactionExcelExport(
                                    from: $data['from'] ?? null,
                                    until: $data['until'] ?? null,
                                    baseQuery: Transaction::where('imported_file_id', $file->id),
                                    importedFile: $file->load('creditCard'),
                                ),
                                'transactions-'.now()->format('Y-m-d-His').'.xlsx',
                            );
                        }),
                ])
                    ->label('Export')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->button(),
            ])
            ->emptyStateHeading('No transactions yet')
            ->emptyStateDescription('Transactions appear here once this file has been processed.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    private function sendRuleSuggestionNotification(Transaction $record): void
    {
        $user = Auth::user();
        /** @var Company|null $tenant */
        $tenant = Filament::getTenant();

        if (! $user || ! $tenant) {
            return;
        }

        $suggestion = app(RuleSuggestionService::class)->suggest($record, $user, $tenant->id);

        if (! $suggestion) {
            return;
        }

        Notification::make()
            ->title('Create a mapping rule?')
            ->body("{$suggestion->matchCount} similar unmapped transaction(s) found for '{$suggestion->pattern}' → '{$suggestion->accountHeadName}'.")
            ->info()
            ->persistent()
            ->actions([
                Action::make('create_rule')
                    ->label('Create Rule')
                    ->button()
                    ->dispatch('openRuleSuggestion', [[
                        'pattern' => $suggestion->pattern,
                        'accountHeadId' => $suggestion->accountHeadId,
                        'importedFileId' => $suggestion->importedFileId,
                        'matchCount' => $suggestion->matchCount,
                    ]])
                    ->close(),
                Action::make('dismiss')
                    ->label('Dismiss')
                    ->color('gray')
                    ->dispatch('dismissRuleSuggestion', [$suggestion->pattern, $tenant->id])
                    ->close(),
            ])
            ->send();
    }
}
