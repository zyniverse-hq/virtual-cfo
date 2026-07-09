<?php

namespace App\Filament\Pages;

use App\Enums\DateRangeWindow;
use App\Enums\ExportFrequency;
use App\Enums\StatementType;
use App\Jobs\SendScheduledTallyExport;
use App\Models\Company;
use App\Models\ScheduledTallyExport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * @property-read Schema $form
 */
class ScheduledExports extends Page
{
    use InteractsWithFormActions;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $title = 'Scheduled Exports';

    protected static ?string $navigationLabel = 'Scheduled Exports';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.scheduled-exports';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    public ?Company $company = null;

    public function mount(): void
    {
        $tenant = Filament::getTenant();
        if ($tenant instanceof Company) {
            $this->company = $tenant;
            $this->form->fill($this->company->attributesToArray());
        }
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->operation('edit')
            ->model($this->company)
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->fullWidth($this->hasFullWidthFormActions())
                    ->sticky($this->areFormActionsSticky())
                    ->key('form-actions'),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Scheduled Exports')
                    ->description('Configure recurring automated Tally XML exports that are emailed to specified recipients on a schedule.')
                    ->schema([
                        Repeater::make('scheduledTallyExports')
                            ->relationship()
                            ->label('')
                            ->schema([
                                Select::make('frequency')
                                    ->options(ExportFrequency::class)
                                    ->required()
                                    ->live()
                                    ->default(ExportFrequency::Weekly),
                                Select::make('day_of_week')
                                    ->label('Day of Week')
                                    ->options([
                                        0 => 'Sunday',
                                        1 => 'Monday',
                                        2 => 'Tuesday',
                                        3 => 'Wednesday',
                                        4 => 'Thursday',
                                        5 => 'Friday',
                                        6 => 'Saturday',
                                    ])
                                    ->default(1)
                                    ->visible(fn (callable $get): bool => $get('frequency') === ExportFrequency::Weekly->value || $get('frequency') === ExportFrequency::Weekly)
                                    ->required(fn (callable $get): bool => $get('frequency') === ExportFrequency::Weekly->value || $get('frequency') === ExportFrequency::Weekly),
                                Select::make('day_of_month')
                                    ->label('Day of Month')
                                    ->options(array_combine(
                                        array_map('strval', range(1, 28)),
                                        array_map('strval', range(1, 28))
                                    ))
                                    ->default('1')
                                    ->visible(fn (callable $get): bool => $get('frequency') === ExportFrequency::Monthly->value || $get('frequency') === ExportFrequency::Monthly)
                                    ->required(fn (callable $get): bool => $get('frequency') === ExportFrequency::Monthly->value || $get('frequency') === ExportFrequency::Monthly)
                                    ->helperText('Capped at 28 to avoid end-of-month issues.'),
                                TimePicker::make('time_of_day')
                                    ->label('Time')
                                    ->default('10:00')
                                    ->seconds(false)
                                    ->timezone('UTC')
                                    ->required(),
                                Select::make('timezone')
                                    ->options([
                                        'Asia/Kolkata' => 'IST (Asia/Kolkata)',
                                        'UTC' => 'UTC',
                                        'America/New_York' => 'EST (America/New_York)',
                                        'Europe/London' => 'GMT (Europe/London)',
                                        'Asia/Dubai' => 'GST (Asia/Dubai)',
                                        'Asia/Singapore' => 'SGT (Asia/Singapore)',
                                    ])
                                    ->default('Asia/Kolkata')
                                    ->required(),
                                Select::make('date_range_window')
                                    ->label('Date Range')
                                    ->options(DateRangeWindow::class)
                                    ->required()
                                    ->default(DateRangeWindow::Previous7Days),
                                Select::make('statement_type')
                                    ->label('Transaction Type')
                                    ->options([
                                        '' => 'All Transactions',
                                        ...array_column(
                                            array_map(
                                                fn (StatementType $type) => ['value' => $type->value, 'label' => $type->getLabel()],
                                                StatementType::cases()
                                            ),
                                            'label',
                                            'value'
                                        ),
                                    ])
                                    ->placeholder('All Transactions')
                                    ->helperText('Filter by Bank, Credit Card, or Invoice transactions. Leave blank for all.'),
                                TagsInput::make('recipient_emails')
                                    ->label('Recipient Emails')
                                    ->placeholder('Enter email addresses')
                                    ->required()
                                    ->nestedRecursiveRules(['email'])
                                    ->columnSpanFull(),
                                Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true)
                                    ->columnSpanFull(),
                                Placeholder::make('last_run_status')
                                    ->label('Status')
                                    ->content(fn (?ScheduledTallyExport $record): string => $record?->last_run_status ?? 'Never run')
                                    ->visible(fn (?ScheduledTallyExport $record): bool => $record !== null),
                                Placeholder::make('last_run_at')
                                    ->label('Last Run')
                                    ->content(fn (?ScheduledTallyExport $record): string => $record?->last_run_at?->diffForHumans() ?? 'N/A')
                                    ->visible(fn (?ScheduledTallyExport $record): bool => $record !== null),
                                Placeholder::make('last_run_message')
                                    ->label('Message')
                                    ->content(fn (?ScheduledTallyExport $record): string => $record?->last_run_message ?? '-')
                                    ->visible(fn (?ScheduledTallyExport $record): bool => (bool) $record?->last_run_message)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add Schedule')
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                $data['created_by'] = Auth::id();
                                $data['statement_type'] = $data['statement_type'] ?: null;

                                return $data;
                            })
                            ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                                $data['statement_type'] = $data['statement_type'] ?: null;

                                return $data;
                            })
                            ->itemLabel(function (array $state): ?string {
                                if (! isset($state['frequency'], $state['time_of_day'])) {
                                    return null;
                                }

                                $frequency = $state['frequency'] instanceof BackedEnum
                                    ? $state['frequency']->value
                                    : (string) $state['frequency'];

                                return ucfirst($frequency) . ' at ' . $state['time_of_day'];
                            }),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $this->form->model($this->company)->saveRelationships();

        Notification::make()
            ->title('Scheduled exports saved successfully.')
            ->success()
            ->send();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->getTestExportAction(),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
        ];
    }

    protected function getTestExportAction(): Action
    {
        return Action::make('testExportNow')
            ->label('Test Export Now')
            ->color('warning')
            ->icon('heroicon-o-paper-airplane')
            ->requiresConfirmation()
            ->modalHeading('Test Export Now')
            ->modalDescription('Select a scheduled export to trigger a test run immediately. The export will be sent to the configured recipients.')
            ->schema([
                Select::make('schedule_id')
                    ->label('Schedule')
                    ->options(function (): array {
                        if (! $this->company) {
                            return [];
                        }

                        return $this->company->scheduledTallyExports()
                            ->where('is_active', true)
                            ->get()
                            ->mapWithKeys(fn (ScheduledTallyExport $s) => [
                                $s->id => $s->schedule_description.' — '.implode(', ', $s->recipient_emails ?? []),
                            ])
                            ->all();
                    })
                    ->required(),
            ])
            ->visible(fn (): bool => $this->company?->scheduledTallyExports()->where('is_active', true)->exists() ?? false)
            ->action(function (array $data): void {
                $schedule = ScheduledTallyExport::find($data['schedule_id']);

                if (! $schedule) {
                    return;
                }

                SendScheduledTallyExport::dispatch($schedule);

                Notification::make()
                    ->title('Test export dispatched — check your email shortly.')
                    ->success()
                    ->send();
            });
    }

    protected function getSaveFormAction(): Action
    {
        return Action::make('save')
            ->label('Save changes')
            ->submit('save')
            ->keyBindings(['mod+s']);
    }
}
