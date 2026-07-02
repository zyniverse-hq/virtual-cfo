<?php

namespace App\Filament\Pages;

use App\Enums\MappingType;
use App\Models\AccountHead;
use App\Models\Company;
use App\Models\Transaction;
use BackedEnum;
use Filament\Actions;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ReviewQueue extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use \App\Filament\Concerns\HasPageTour;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $title = 'Review Queue';

    protected static ?string $navigationLabel = 'Review Queue';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.review-queue';

    protected function getHeaderActions(): array
    {
        return [
            $this->getPageTourAction(),
        ];
    }

    public function getFooter(): ?\Illuminate\Contracts\View\View
    {
        return $this->getPageTourFooter('review-queue');
    }

    public function table(Table $table): Table
    {
        /** @var Company $company */
        $company = Filament::getTenant();
        $threshold = (float) $company->review_confidence_threshold;

        return $table
            ->query(
                Transaction::query()
                    ->where('company_id', $company->getKey())
                    ->where('mapping_type', MappingType::Ai)
                    ->where('ai_confidence', '<', $threshold)
                    ->with(['accountHead', 'importedFile'])
            )
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->limit(50)
                    ->tooltip(fn (Transaction $record): string => $record->description),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->state(fn (Transaction $record): ?string => $record->debit ?? $record->credit)
                    ->numeric(decimalPlaces: 2)
                    ->color(fn (Transaction $record): string => $record->debit ? 'danger' : 'success')
                    ->icon(fn (Transaction $record): string => $record->debit ? 'heroicon-m-arrow-up' : 'heroicon-m-arrow-down')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('accountHead.name')
                    ->label('AI Suggested Head'),

                Tables\Columns\TextColumn::make('ai_confidence')
                    ->label('Confidence')
                    ->formatStateUsing(fn (float $state): string => number_format($state * 100, 0).'%')
                    ->color(fn (float $state): string => match (true) {
                        $state >= 0.7 => 'warning',
                        $state >= 0.5 => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('importedFile.original_filename')
                    ->label('Source File')
                    ->limit(20)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('ai_confidence', 'asc')
            ->recordActions([
                Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (Transaction $record) => self::approveMapping($record)),

                Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (Transaction $record) => self::rejectMapping($record)),

                Actions\Action::make('reassign')
                    ->label('Reassign')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->schema([
                        Select::make('account_head_id')
                            ->label('Account Head')
                            ->options(fn () => AccountHead::where('company_id', Filament::getTenant()?->getKey())
                                ->where('is_active', true)
                                ->pluck('name', 'id'))
                            ->required()
                            ->searchable(),
                    ])
                    ->action(function (Transaction $record, array $data) {
                        $record->update([
                            'mapping_type' => MappingType::Manual,
                            'account_head_id' => $data['account_head_id'],
                        ]);
                    }),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('bulk_approve')
                        ->label('Approve Selected')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            DB::transaction(function () use ($records) {
                                /** @var Collection<int, Transaction> $records */
                                $records->each(fn (Transaction $record) => self::approveMapping($record));
                            });
                        })
                        ->deselectRecordsAfterCompletion(),

                    Actions\BulkAction::make('bulk_reject')
                        ->label('Reject Selected')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            DB::transaction(function () use ($records) {
                                /** @var Collection<int, Transaction> $records */
                                $records->each(fn (Transaction $record) => self::rejectMapping($record));
                            });
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getNavigationBadge(): ?string
    {
        /** @var Company|null $company */
        $company = Filament::getTenant();

        if (! $company) {
            return null;
        }

        $threshold = (float) $company->review_confidence_threshold;

        $count = Transaction::query()
            ->where('company_id', $company->getKey())
            ->where('mapping_type', MappingType::Ai)
            ->where('ai_confidence', '<', $threshold)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    private static function approveMapping(Transaction $record): void
    {
        $record->update(['mapping_type' => MappingType::Manual]);
    }

    private static function rejectMapping(Transaction $record): void
    {
        $record->update([
            'mapping_type' => MappingType::Unmapped,
            'account_head_id' => null,
        ]);
    }
}
