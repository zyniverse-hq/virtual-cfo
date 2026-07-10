<?php

namespace App\Filament\Resources;

use App\Enums\InboundEmailStatus;
use App\Enums\NavigationGroup;
use App\Filament\Resources\InboundEmailResource\Pages;
use App\Models\Company;
use App\Models\InboundEmail;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class InboundEmailResource extends Resource
{
    protected static ?string $model = InboundEmail::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Inbound Emails';

    protected static ?string $modelLabel = 'Inbound Email';

    protected static ?string $pluralModelLabel = 'Inbound Emails';

    protected static UnitEnum|string|null $navigationGroup = NavigationGroup::Monitoring;

    protected static ?int $navigationSort = 1;

    protected static bool $isScopedToTenant = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()->currentRole()?->canManageTeam() ?? false;
    }

    /** @return Builder<InboundEmail> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        /** @var Builder<InboundEmail> */
        return parent::getEloquentQuery()->where('company_id', $company->id);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('received_at')
                    ->label('Date')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->icon('heroicon-m-calendar'),

                Tables\Columns\TextColumn::make('from_address')
                    ->label('Email & Subject')
                    ->weight(FontWeight::Bold)
                    ->description(fn (InboundEmail $record): ?string => str($record->subject)->limit(50)->toString())
                    ->searchable(['from_address', 'subject']),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge(),

                Tables\Columns\TextColumn::make('attachment_count')
                    ->label('Attachments')
                    ->alignCenter()
                    ->icon('heroicon-m-paper-clip'),

                Tables\Columns\TextColumn::make('processed_count')
                    ->label('Processed')
                    ->alignCenter()
                    ->color('success'),
            ])
            ->defaultSort('received_at', 'desc')
            ->emptyStateHeading('No inbound emails yet')
            ->emptyStateDescription('Statements sent to your dedicated email address will automatically appear here.')
            ->emptyStateIcon('heroicon-o-inbox')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(InboundEmailStatus::class),

                Tables\Filters\Filter::make('received_at')
                    ->form([
                        DatePicker::make('from')->label('From Date'),
                        DatePicker::make('until')->label('Until Date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn (Builder $q, string $date) => $q->whereDate('received_at', '>=', $date))
                            ->when($data['until'], fn (Builder $q, string $date) => $q->whereDate('received_at', '<=', $date));
                    }),
            ])
            ->actions([
                ActionGroup::make([
                    ViewAction::make(),
                ])->icon('heroicon-m-ellipsis-vertical'),
            ])
            ->recordUrl(fn (InboundEmail $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInboundEmails::route('/'),
            'view' => Pages\ViewInboundEmail::route('/{record}'),
        ];
    }
}
