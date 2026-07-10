<?php

namespace App\Filament\Resources;

use App\Enums\NavigationGroup;
use App\Filament\Resources\AccountHeadResource\Pages;
use App\Models\AccountHead;
use App\Models\Company;
use App\Services\TallyImport\TallyMasterImportService;
use BackedEnum;
use Closure;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class AccountHeadResource extends Resource
{
    protected static ?string $model = AccountHead::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Account Heads';

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Configuration;

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('The display name for this account head, as it appears in Tally.')
                            ->unique(
                                table: AccountHead::class,
                                column: 'name',
                                ignoreRecord: true,
                                modifyRuleUsing: function (Unique $rule, Get $get): Unique {
                                    return $rule
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->where('group_name', $get('group_name'))
                                        ->whereNull('deleted_at');
                                },
                            ),

                        Forms\Components\Select::make('parent_id')
                            ->label('Parent Head')
                            ->relationship('parent', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('None (Top Level)')
                            ->helperText('Select a parent to create a hierarchy matching your Tally chart of accounts.'),

                        Forms\Components\TextInput::make('group_name')
                            ->label('Group Name')
                            ->maxLength(255)
                            ->helperText('The Tally group this head belongs to (e.g., Current Assets, Direct Expenses).'),

                        Forms\Components\TextInput::make('tally_guid')
                            ->label('Tally GUID')
                            ->maxLength(255)
                            ->helperText('Auto-populated when importing from Tally XML. Used to sync updates.'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Inactive heads will not appear in transaction mapping suggestions.'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('parent.name')
                    ->label('Parent')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('group_name')
                    ->label('Group')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('transactions_count')
                    ->label('Transactions')
                    ->counts('transactions')
                    ->sortable(),

                Tables\Columns\TextColumn::make('head_mappings_count')
                    ->label('Rules')
                    ->counts('headMappings')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                TrashedFilter::make(),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),

                Tables\Filters\SelectFilter::make('group_name')
                    ->label('Group')
                    ->options(fn () => AccountHead::whereNotNull('group_name')
                        ->distinct()
                        ->pluck('group_name', 'group_name')),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->before(function (AccountHead $record, Actions\DeleteAction $action) {
                        self::validateDeletion($record, $action);
                    }),
                Actions\ForceDeleteAction::make()
                    ->before(function (AccountHead $record, Actions\ForceDeleteAction $action) {
                        self::validateDeletion($record, $action);
                    }),
                Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->before(function (\Illuminate\Database\Eloquent\Collection $records, Actions\DeleteBulkAction $action) {
                            $counts = \App\Models\Transaction::whereIn('account_head_id', $records->pluck('id'))
                                ->selectRaw('account_head_id, count(*) as count')
                                ->groupBy('account_head_id')
                                ->pluck('count', 'account_head_id');

                            foreach ($records as $record) {
                                /** @var AccountHead $record */
                                self::validateDeletion($record, $action, $counts->get($record->id, 0));
                            }
                        }),
                    Actions\ForceDeleteBulkAction::make()
                        ->before(function (\Illuminate\Database\Eloquent\Collection $records, Actions\ForceDeleteBulkAction $action) {
                            $counts = \App\Models\Transaction::whereIn('account_head_id', $records->pluck('id'))
                                ->selectRaw('account_head_id, count(*) as count')
                                ->groupBy('account_head_id')
                                ->pluck('count', 'account_head_id');

                            foreach ($records as $record) {
                                /** @var AccountHead $record */
                                self::validateDeletion($record, $action, $counts->get($record->id, 0));
                            }
                        }),
                    Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->headerActions([
                self::makeTallyImportAction('import_tally')
                    ->color('info'),
            ])
            ->emptyStateHeading('No account heads yet')
            ->emptyStateDescription('Import your chart of accounts from Tally to get started. This enables automatic transaction matching.')
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateActions([
                self::makeTallyImportAction('import_tally_empty')
                    ->color('primary'),
            ]);
    }

    /** @return Builder<AccountHead> */
    public static function getEloquentQuery(): Builder
    {
        return AccountHead::query()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccountHeads::route('/'),
            'create' => Pages\CreateAccountHead::route('/create'),
            'edit' => Pages\EditAccountHead::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<Actions\Action|Component>
     */
    private static function tallyImportForm(): array
    {
        return [
            Forms\Components\FileUpload::make('xml_file')
                ->label('Tally XML File')
                ->acceptedFileTypes(['text/xml', 'application/xml'])
                ->mimeTypeMap(['xml' => 'text/xml'])
                ->maxSize(51200) // 50MB
                ->required()
                ->disk('local')
                ->directory('tally-imports')
                ->visibility('private'),
        ];
    }

    private static function tallyImportAction(): Closure
    {
        return function (array $data): void {
            $filePath = $data['xml_file'];
            $xmlContent = Storage::disk('local')->get($filePath);
            /** @var Company $company */
            $company = Filament::getTenant();

            $service = new TallyMasterImportService;
            $result = $service->import($xmlContent, $company);

            Storage::disk('local')->delete($filePath);

            if ($result->hasErrors()) {
                Notification::make()
                    ->title('Import failed')
                    ->body($result->errors[0])
                    ->danger()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Tally masters imported')
                ->body(sprintf(
                    'Created %d, updated %d account heads. %d bank accounts created.',
                    $result->totalCreated(),
                    $result->totalUpdated(),
                    $result->bankAccountsCreated,
                ))
                ->success()
                ->send();
        };
    }

    private static function makeTallyImportAction(string $name): Actions\Action
    {
        return Actions\Action::make($name)
            ->label('Import from Tally XML')
            ->icon('heroicon-o-arrow-up-tray')
            ->form(self::tallyImportForm())
            ->action(self::tallyImportAction());
    }

    public static function validateDeletion(AccountHead $record, \Filament\Actions\Action|\Filament\Actions\BulkAction $action, ?int $preloadedCount = null): void
    {
        $count = $preloadedCount ?? $record->getMappedTransactionCount();
        if ($count > 0) {
            Notification::make()
                ->danger()
                ->title($record->getDeletionErrorMessage($count))
                ->actions([
                    \Filament\Actions\Action::make('view_transactions')
                        ->label('View Transactions')
                        ->url(\App\Filament\Resources\TransactionResource::getUrl('index', [
                            'tableFilters' => [
                                'account_head_id' => ['value' => (string) $record->id],
                            ],
                            'filters' => [
                                'account_head_id' => ['value' => (string) $record->id],
                            ],
                        ]))
                        ->button(),
                ])
                ->send();

            $action->cancel();
        }
    }
}
