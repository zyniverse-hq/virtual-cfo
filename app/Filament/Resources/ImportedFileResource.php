<?php

namespace App\Filament\Resources;

use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Enums\StatementType;
use App\Filament\Resources\ImportedFileResource\Pages;
use App\Jobs\ProcessImportedFile;
use App\Models\ImportedFile;
use App\Services\StatementClassifier;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

class ImportedFileResource extends Resource
{
    protected static ?string $model = ImportedFile::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-up';

    protected static ?string $navigationLabel = 'Imported Files';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Upload Statement')
                    ->schema([
                        Forms\Components\TextInput::make('display_name')
                            ->label('Display Name')
                            ->placeholder('e.g. HDFC Regalia Jan 2025 — leave blank to auto-generate')
                            ->columnSpanFull(),

                        Forms\Components\FileUpload::make('file_path')
                            ->label('Statement File')
                            ->acceptedFileTypes([
                                'application/pdf',
                                'text/csv',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ])
                            ->directory('statements')
                            ->disk('local')
                            ->maxSize(10240) // 10MB
                            ->required()
                            ->columnSpanFull(),

                        Forms\Components\Select::make('statement_type')
                            ->label('Statement Type')
                            ->options(StatementType::class)
                            ->default(StatementType::Bank)
                            ->required()
                            ->live()
                            ->helperText('Select the type of document you are uploading.'),

                        Forms\Components\Select::make('bank_account_id')
                            ->label('Account')
                            ->relationship('bankAccount', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Auto-detect from statement')
                            ->visible(fn (Get $get): bool => $get('statement_type') === StatementType::Bank),

                        Forms\Components\Select::make('credit_card_id')
                            ->label('Credit Card')
                            ->relationship('creditCard', 'name')
                            ->searchable()
                            ->preload()
                            ->placeholder('Auto-detect from statement')
                            ->visible(fn (Get $get): bool => $get('statement_type') === StatementType::CreditCard),

                        Forms\Components\TextInput::make('pdf_password')
                            ->label('PDF Password (optional)')
                            ->password()
                            ->revealable()
                            ->helperText('If the PDF is password-protected, enter the password here.')
                            ->columnSpanFull(),

                        Forms\Components\Toggle::make('force_reimport')
                            ->label('Force re-import')
                            ->helperText('If this file was already imported, delete the previous import and re-import.')
                            ->default(false)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll(fn (): ?string => ImportedFile::activelyProcessing()->exists() ? '10s' : null)
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['bankAccount', 'creditCard', 'uploader']))
            ->columns([
                Tables\Columns\TextColumn::make('display_name')
                    ->label('File')
                    ->searchable()
                    ->limit(40)
                    ->description(fn (ImportedFile $record): ?string => $record->total_rows
                        ? $record->total_rows.' '.str('transaction')->plural($record->total_rows)
                        : null
                    ),

                Tables\Columns\TextColumn::make('bankAccount.name')
                    ->label('Account')
                    ->state(function (ImportedFile $record): string {
                        return $record->bankAccount?->name
                            ?? $record->creditCard?->name
                            ?? $record->bank_name
                            ?? ($record->isProcessing() ? 'Detecting...' : 'Not detected');
                    })
                    ->description(fn (ImportedFile $record): string => $record->statement_type->getLabel())
                    ->searchable(),

                Tables\Columns\TextColumn::make('statement_period')
                    ->label('Period')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('source')
                    ->label('Source')
                    ->badge(),

                Tables\Columns\TextColumn::make('status')
                    ->badge(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->description(fn (ImportedFile $record): string => 'by '.($record->uploader?->name ?? 'System')),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TrashedFilter::make(),

                Tables\Filters\SelectFilter::make('status')
                    ->options(ImportStatus::class),

                Tables\Filters\SelectFilter::make('statement_type')
                    ->options(StatementType::class),

                Tables\Filters\SelectFilter::make('source')
                    ->options(ImportSource::class),
            ])
            ->actions([
                Actions\ViewAction::make(),

                Actions\ActionGroup::make([
                    Actions\Action::make('download')
                        ->label('Download')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('gray')
                        ->url(fn (ImportedFile $record): string => route('imported-files.download', $record))
                        ->openUrlInNewTab(),

                    Actions\Action::make('setPassword')
                        ->label('Set Password')
                        ->icon('heroicon-o-key')
                        ->color('warning')
                        ->schema([
                            Forms\Components\TextInput::make('pdf_password')
                                ->label('PDF Password')
                                ->password()
                                ->revealable()
                                ->required(),
                        ])
                        ->modalHeading('Set PDF Password')
                        ->modalDescription('Enter the password for this PDF. The file will be re-processed automatically.')
                        ->modalSubmitActionLabel('Decrypt & Process')
                        ->action(function (ImportedFile $record, array $data) {
                            $metadata = $record->source_metadata ?? [];
                            $metadata['manual_password'] = $data['pdf_password'];

                            DB::transaction(function () use ($record, $metadata) {
                                $record->transactions()->delete();
                                $record->update([
                                    'source_metadata' => $metadata,
                                    'status' => ImportStatus::Pending,
                                    'total_rows' => 0,
                                    'mapped_rows' => 0,
                                    'error_message' => null,
                                ]);
                            });

                            ProcessImportedFile::dispatch($record);
                        })
                        ->visible(fn (ImportedFile $record) => $record->status === ImportStatus::NeedsPassword),

                    Actions\Action::make('changeType')
                        ->label('Change Type')
                        ->icon('heroicon-o-arrow-path-rounded-square')
                        ->color('gray')
                        ->schema([
                            Forms\Components\Select::make('statement_type')
                                ->label('Statement Type')
                                ->options(StatementType::class)
                                ->required(),
                        ])
                        ->fillForm(fn (ImportedFile $record): array => [
                            'statement_type' => $record->statement_type,
                        ])
                        ->modalHeading('Change Statement Type')
                        ->modalDescription('Change the statement type for this file. Existing transactions will be deleted and the file will be re-processed with the new type.')
                        ->modalSubmitActionLabel('Update & Re-process')
                        ->action(function (ImportedFile $record, array $data): void {
                            DB::transaction(function () use ($record, $data): void {
                                $record->transactions()->delete();
                                $record->update([
                                    'statement_type' => $data['statement_type'],
                                    'status' => ImportStatus::Pending,
                                    'total_rows' => 0,
                                    'mapped_rows' => 0,
                                    'error_message' => null,
                                ]);
                            });

                            ProcessImportedFile::dispatch($record);
                        })
                        ->visible(fn (ImportedFile $record) => in_array($record->status, [
                            ImportStatus::Completed,
                            ImportStatus::Failed,
                        ])),

                    Actions\Action::make('reprocess')
                        ->label('Re-process')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (ImportedFile $record) {
                            $reclassified = (new StatementClassifier)->classifyFromMetadata($record);

                            DB::transaction(function () use ($record, $reclassified) {
                                $record->transactions()->delete();
                                $record->update([
                                    'status' => ImportStatus::Pending,
                                    'total_rows' => 0,
                                    'mapped_rows' => 0,
                                    'error_message' => null,
                                    ...($reclassified ? ['statement_type' => $reclassified] : []),
                                ]);
                            });
                            ProcessImportedFile::dispatch($record);
                        })
                        ->visible(fn (ImportedFile $record) => in_array($record->status, [
                            ImportStatus::Completed,
                            ImportStatus::Failed,
                        ])),

                    Actions\DeleteAction::make(),
                    Actions\ForceDeleteAction::make(),
                    Actions\RestoreAction::make(),
                ]),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                    Actions\ForceDeleteBulkAction::make(),
                    Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->headerActions([
                Actions\CreateAction::make()
                    ->label('Upload Statement')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->extraAttributes(['class' => 'tour-upload-statement']),
            ])
            ->emptyStateHeading('No imported files yet')
            ->emptyStateDescription('Upload a bank statement, credit card statement, or invoice to get started.')
            ->emptyStateIcon('heroicon-o-document-arrow-up');
    }

    /** @return Builder<ImportedFile> */
    public static function getEloquentQuery(): Builder
    {
        return ImportedFile::query()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ImportedFileResource\RelationManagers\TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportedFiles::route('/'),
            'create' => Pages\CreateImportedFile::route('/create'),
            'view' => Pages\ViewImportedFile::route('/{record}'),
        ];
    }
}
