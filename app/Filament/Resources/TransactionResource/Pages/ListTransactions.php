<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Enums\MappingType;
use App\Enums\MatchType;
use App\Filament\Concerns\HasPageTour;
use App\Filament\Resources\TransactionResource;
use App\Filament\Widgets\TransactionStatsOverview;
use App\Models\AccountHead;
use App\Models\Company;
use App\Models\HeadMapping;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

class ListTransactions extends ListRecords
{
    use HasPageTour;

    protected static string $resource = TransactionResource::class;

    public function getSubheading(): ?string
    {
        return 'Review, map, and export your parsed transactions';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->getPageTourAction(),

            Action::make('suggestRule')
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
                        $applied = $this->applyRuleToImport(
                            pattern: $data['pattern'],
                            matchType: $data['match_type'] instanceof MatchType ? $data['match_type'] : MatchType::from($data['match_type']),
                            accountHeadId: $data['account_head_id'],
                            importedFileId: (int) $data['imported_file_id'],
                        );

                        if ($applied > 0) {
                            $this->resetTable();
                        }
                    }

                    Notification::make()
                        ->title('Mapping rule created')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TransactionStatsOverview::class,
        ];
    }

    public function getFooter(): ?View
    {
        return $this->getPageTourFooter('transactions');
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

        // Descriptions are encrypted; matching must be done in PHP after loading.
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
}
