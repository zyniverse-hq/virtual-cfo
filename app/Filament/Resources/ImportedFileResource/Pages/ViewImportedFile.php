<?php

namespace App\Filament\Resources\ImportedFileResource\Pages;

use App\Enums\MappingType;
use App\Enums\MatchType;
use App\Filament\Resources\ImportedFileResource;
use App\Jobs\MatchTransactionHeads;
use App\Models\AccountHead;
use App\Models\Company;
use App\Models\HeadMapping;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

/** @property ImportedFile $record */
class ViewImportedFile extends ViewRecord
{
    protected static string $resource = ImportedFileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('rematch')
                ->label('Re-run AI Matching')
                ->icon('heroicon-o-sparkles')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Re-run AI Matching')
                ->modalDescription('This will re-run rule-based and AI matching on all unmapped transactions in this file.')
                ->visible(fn (ImportedFile $record): bool => $record->transactions()->where('mapping_type', MappingType::Unmapped)->exists())
                ->action(function (ImportedFile $record): void {
                    $record->update(['is_matching' => true]);
                    MatchTransactionHeads::dispatch($record);

                    Notification::make()
                        ->title('Matching queued')
                        ->body('AI matching will run in the background.')
                        ->success()
                        ->send();

                    $this->js(<<<'JS'
                        const poll = setInterval(async () => {
                            const done = await $wire.pollMatchStatus();
                            if (done) clearInterval(poll);
                        }, 3000);
                    JS);
                }),

            Actions\Action::make('download')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (): string => route('imported-files.download', $this->record))
                ->openUrlInNewTab(),

            Actions\Action::make('suggestRule')
                ->label('Create Mapping Rule')
                ->icon('heroicon-o-sparkles')
                ->color('info')
                ->fillForm(fn (array $arguments): array => $arguments)
                ->form([
                    Forms\Components\TextInput::make('pattern')
                        ->label('Pattern')
                        ->required(),

                    Forms\Components\Select::make('match_type')
                        ->options(MatchType::class)
                        ->default(MatchType::Contains)
                        ->required(),

                    Forms\Components\Select::make('account_head_id')
                        ->label('Account Head')
                        ->options(fn () => AccountHead::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->required(),

                    Forms\Components\Hidden::make('imported_file_id'),

                    Forms\Components\Toggle::make('apply_immediately')
                        ->label('Apply to matching transactions in this import')
                        ->default(true),
                ])
                ->action(function (array $data): void {
                    /** @var Company|null $tenant */
                    $tenant = Filament::getTenant();
                    $companyId = $tenant?->id;

                    try {
                        HeadMapping::create([
                            'pattern' => $data['pattern'],
                            'match_type' => $data['match_type'],
                            'account_head_id' => $data['account_head_id'],
                            'company_id' => $companyId,
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

                    if ($data['apply_immediately'] && ! empty($data['imported_file_id'])) {
                        $this->applyRuleToImport(
                            pattern: $data['pattern'],
                            matchType: $data['match_type'] instanceof MatchType ? $data['match_type'] : MatchType::from($data['match_type']),
                            accountHeadId: $data['account_head_id'],
                            importedFileId: (int) $data['imported_file_id'],
                        );
                    }

                    Notification::make()
                        ->title('Mapping rule created')
                        ->success()
                        ->send();
                }),
        ];
    }

    /** @param array<string, mixed> $data */
    #[On('openRuleSuggestion')]
    public function openRuleSuggestion(array $data): void
    {
        $this->mountAction('suggestRule', [
            'pattern' => $data['pattern'] ?? '',
            'match_type' => MatchType::Contains->value,
            'account_head_id' => $data['accountHeadId'] ?? null,
            'imported_file_id' => $data['importedFileId'] ?? null,
            'apply_immediately' => true,
        ]);
    }

    public function pollMatchStatus(): bool
    {
        $this->record->refresh();

        if ($this->record->is_matching) {
            return false;
        }

        $mapped = $this->record->transactions()->where('mapping_type', '!=', MappingType::Unmapped)->count();
        $total = $this->record->total_rows ?? 0;

        Notification::make()
            ->title('Head matching completed')
            ->body("Matched {$mapped} of {$total} transactions.")
            ->persistent()
            ->success()
            ->send();

        $lowConfidence = $this->record->transactions()
            ->where('mapping_type', MappingType::Ai)
            ->where('ai_confidence', '<', 0.8)
            ->count();

        if ($lowConfidence > 0) {
            Notification::make()
                ->title('Some matches need your review')
                ->body("{$lowConfidence} transaction(s) have low confidence AI matches. Check the Review Queue.")
                ->persistent()
                ->info()
                ->send();
        }

        $this->refreshFormData(['mapped_rows', 'is_matching']);

        return true;
    }

    #[On('dismissRuleSuggestion')]
    public function dismissRuleSuggestion(string $pattern, int $companyId): void
    {
        /** @var User $user */
        $user = Auth::user();
        $dismissed = $user->dismissed_suggestions ?? [];
        $key = "{$companyId}:{$pattern}";

        if (! in_array($key, $dismissed)) {
            $dismissed[] = $key;
            $user->update(['dismissed_suggestions' => $dismissed]);
        }
    }

    private function applyRuleToImport(string $pattern, MatchType $matchType, int $accountHeadId, int $importedFileId): int
    {
        $file = ImportedFile::find($importedFileId);

        if (! $file) {
            return 0;
        }

        $rule = new HeadMapping(['pattern' => $pattern, 'match_type' => $matchType]);

        $toUpdate = Transaction::where('imported_file_id', $importedFileId)
            ->where('mapping_type', MappingType::Unmapped)
            ->get()
            ->filter(fn (Transaction $t) => $rule->matches($t->description));

        foreach ($toUpdate as $t) {
            $t->update([
                'account_head_id' => $accountHeadId,
                'mapping_type' => MappingType::Auto,
            ]);
        }

        $count = $toUpdate->count();

        $file->update([
            'mapped_rows' => $file->transactions()
                ->where('mapping_type', '!=', MappingType::Unmapped)
                ->count(),
        ]);

        if ($count > 0) {
            Notification::make()
                ->title("Rule applied to {$count} transaction(s)")
                ->success()
                ->send();
        }

        return $count;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('File Details')
                    ->poll(fn (): string => $this->record->isProcessing() ? '10s' : '30s')
                    ->schema([
                        Infolists\Components\TextEntry::make('display_name')
                            ->label('Display Name'),
                        Infolists\Components\TextEntry::make('original_filename')
                            ->label('Filename'),
                        Infolists\Components\TextEntry::make('bank_name')
                            ->label('Detected Bank')
                            ->placeholder('Not detected'),
                        Infolists\Components\TextEntry::make('statement_period')
                            ->label('Statement Period')
                            ->placeholder('Not detected'),
                        Infolists\Components\TextEntry::make('bankAccount.name')
                            ->label('Bank Account')
                            ->visible(fn (ImportedFile $record): bool => $record->bank_account_id !== null),
                        Infolists\Components\TextEntry::make('creditCard.name')
                            ->label('Credit Card')
                            ->visible(fn (ImportedFile $record): bool => $record->credit_card_id !== null),
                        Infolists\Components\TextEntry::make('statement_type')
                            ->label('Type')
                            ->badge(),
                        Infolists\Components\TextEntry::make('status')
                            ->badge(),
                        Infolists\Components\TextEntry::make('source')
                            ->badge(),
                        Infolists\Components\TextEntry::make('total_rows')
                            ->label('Total Transactions'),
                        Infolists\Components\TextEntry::make('mapped_rows')
                            ->label('Mapped Transactions'),
                        Infolists\Components\TextEntry::make('mapped_percentage')
                            ->label('Mapped %')
                            ->suffix('%'),
                        Infolists\Components\TextEntry::make('uploader.name')
                            ->label('Uploaded By'),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Uploaded At')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('processed_at')
                            ->label('Processed At')
                            ->dateTime()
                            ->placeholder('Not processed'),
                        Infolists\Components\TextEntry::make('error_message')
                            ->label('Error')
                            ->visible(fn (ImportedFile $record) => $record->error_message !== null)
                            ->color('danger')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }
}
