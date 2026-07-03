<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\ConnectorProvider;
use App\Enums\DateRangeWindow;
use App\Enums\ExportFrequency;
use App\Enums\StatementType;
use App\Enums\ZohoDataCenter;
use App\Jobs\SendScheduledTallyExport;
use App\Models\Company;
use App\Models\Connector;
use App\Models\ScheduledTallyExport;
use App\Services\Connectors\ZohoInvoiceService;
use App\Support\GstinValidator;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class EditCompanySettings extends EditTenantProfile
{
    /**
     * @var array<string, array<string, string>>
     */
    protected $queryString = [
        'zohoStatus' => ['as' => 'zoho_status', 'except' => ''],
        'zohoError' => ['as' => 'zoho_error', 'except' => ''],
    ];

    public string $zohoStatus = '';

    public string $zohoError = '';

    public function mount(): void
    {
        parent::mount();

        if ($this->zohoStatus === 'connected') {
            Notification::make()
                ->title('Zoho Invoice connected successfully.')
                ->success()
                ->send();
        }

        if ($this->zohoError !== '') {
            Notification::make()
                ->title($this->zohoError)
                ->danger()
                ->send();
        }
    }

    public static function getLabel(): string
    {
        return 'Company settings';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Company Details')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('gstin')
                            ->label('GSTIN')
                            ->rules(['nullable', fn () => function (string $attribute, mixed $value, \Closure $fail) {
                                if ($value && ! GstinValidator::isValid($value)) {
                                    $fail('The GSTIN format is invalid.');
                                }
                            }]),
                        TextInput::make('state')
                            ->maxLength(255),
                        Select::make('gst_registration_type')
                            ->label('GST Registration Type')
                            ->options([
                                'Regular' => 'Regular',
                                'Composition' => 'Composition',
                                'Unregistered' => 'Unregistered',
                            ])
                            ->default('Regular'),
                        Select::make('fy_start_month')
                            ->label('Financial Year Starts In')
                            ->options([
                                1 => 'January',
                                2 => 'February',
                                3 => 'March',
                                4 => 'April',
                                5 => 'May',
                                6 => 'June',
                                7 => 'July',
                                8 => 'August',
                                9 => 'September',
                                10 => 'October',
                                11 => 'November',
                                12 => 'December',
                            ])
                            ->default(4)
                            ->required()
                            ->helperText('The month your financial year begins (e.g., April for India/UK, January for US, July for Australia).'),
                        Select::make('currency')
                            ->options([
                                'INR' => 'INR',
                                'USD' => 'USD',
                                'EUR' => 'EUR',
                                'GBP' => 'GBP',
                            ])
                            ->default('INR'),
                    ]),

                Section::make('Account Holder Identity')
                    ->description('Account holder details for reference and verification.')
                    ->schema([
                        TextInput::make('account_holder_name')
                            ->label('Account Holder Name')
                            ->helperText('As registered with the bank.'),
                        TextInput::make('date_of_birth')
                            ->label('Date of Birth')
                            ->type('date'),
                        TextInput::make('pan_number')
                            ->label('PAN Number')
                            ->regex('/^[A-Z]{5}[0-9]{4}[A-Z]$/')
                            ->helperText('10-character PAN (e.g., ABCDE1234F).'),
                        TextInput::make('mobile_number')
                            ->label('Mobile Number')
                            ->tel(),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->columns(2),

                Section::make('Email Forwarding')
                    ->schema([
                        TextInput::make('inbox_address')
                            ->label('Inbox Address')
                            ->helperText('Forward invoices to this email address for automatic processing.')
                            ->disabled()
                            ->placeholder('Generated on company registration'),
                    ]),

                Section::make('Tally Ledger Settings')
                    ->description('Ledger names must match exactly what is configured in your Tally company. Use {rate} as a placeholder for the GST rate percentage (e.g. "Input Igst @ {rate}%").')
                    ->schema([
                        TextInput::make('tally_input_igst_ledger')
                            ->label('Input IGST Ledger')
                            ->placeholder('Input Igst @ {rate}%')
                            ->helperText('Used for purchase invoice IGST entries.'),
                        TextInput::make('tally_output_igst_ledger')
                            ->label('Output IGST Ledger')
                            ->placeholder('Output Igst @ {rate}%')
                            ->helperText('Used for sales invoice IGST entries.'),
                        TextInput::make('tally_input_cgst_ledger')
                            ->label('Input CGST Ledger')
                            ->placeholder('Input Cgst @ {rate}%'),
                        TextInput::make('tally_output_cgst_ledger')
                            ->label('Output CGST Ledger')
                            ->placeholder('Output Cgst @ {rate}%'),
                        TextInput::make('tally_input_sgst_ledger')
                            ->label('Input SGST Ledger')
                            ->placeholder('Input Sgst @ {rate}%'),
                        TextInput::make('tally_output_sgst_ledger')
                            ->label('Output SGST Ledger')
                            ->placeholder('Output Sgst @ {rate}%'),
                        TextInput::make('tally_tds_payable_ledger')
                            ->label('TDS Payable Ledger')
                            ->placeholder('TDS Payable')
                            ->helperText('Used when a TDS deduction is present on an invoice.'),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->columns(2),

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
                                    ->visible(fn (callable $get): bool => $get('frequency') === ExportFrequency::Weekly->value)
                                    ->required(fn (callable $get): bool => $get('frequency') === ExportFrequency::Weekly->value),
                                Select::make('day_of_month')
                                    ->label('Day of Month')
                                    ->options(array_combine(range(1, 28), range(1, 28)))
                                    ->default(1)
                                    ->visible(fn (callable $get): bool => $get('frequency') === ExportFrequency::Monthly->value)
                                    ->required(fn (callable $get): bool => $get('frequency') === ExportFrequency::Monthly->value)
                                    ->helperText('Capped at 28 to avoid end-of-month issues.'),
                                TimePicker::make('time_of_day')
                                    ->label('Time')
                                    ->default('10:00')
                                    ->seconds(false)
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
                            }),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Section::make('Integrations')
                    ->schema(fn () => $this->getIntegrationsSchema()),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('connectZoho')
                ->label('Connect Zoho Invoice')
                ->color('primary')
                ->visible(fn () => $this->getZohoConnector() === null)
                ->modalHeading('Connect Zoho Invoice')
                ->modalDescription('Enter your Zoho OAuth app credentials. You can find these in the Zoho API Console.')
                ->schema([
                    Select::make('data_center')
                        ->label('Data Center')
                        ->options(ZohoDataCenter::class)
                        ->required()
                        ->default(ZohoDataCenter::India->value),
                    TextInput::make('client_id')
                        ->label('Client ID')
                        ->required()
                        ->placeholder('1000.XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX'),
                    TextInput::make('client_secret')
                        ->label('Client Secret')
                        ->required()
                        ->password()
                        ->revealable(),
                ])
                ->modalSubmitActionLabel('Connect')
                ->action(function (array $data): void {
                    /** @var Company $company */
                    $company = Filament::getTenant();

                    Connector::updateOrCreate(
                        [
                            'company_id' => $company->id,
                            'provider' => ConnectorProvider::Zoho,
                        ],
                        [
                            'settings' => [
                                'data_center' => $data['data_center'],
                                'client_id' => $data['client_id'],
                                'client_secret' => $data['client_secret'],
                            ],
                            'is_active' => false,
                        ],
                    );

                    $this->redirect(route('connectors.zoho.redirect', ['company' => $company]));
                }),

            Action::make('syncZoho')
                ->label('Sync Now')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('This will sync invoices from Zoho Invoice. This may take a moment.')
                ->visible(fn () => $this->getZohoConnector() !== null)
                ->action(function (): void {
                    $connector = $this->getZohoConnector();

                    if (! $connector) {
                        return;
                    }

                    try {
                        $service = app(ZohoInvoiceService::class);
                        $count = $service->syncForCompany($connector);

                        Notification::make()
                            ->title("Synced {$count} invoices from Zoho.")
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Sync failed: '.$e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('disconnectZoho')
                ->label('Disconnect Zoho')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Disconnect Zoho Invoice')
                ->modalDescription('This will revoke access to your Zoho Invoice account. Previously imported invoices will not be affected.')
                ->visible(fn () => $this->getZohoConnector() !== null)
                ->action(function (): void {
                    $connector = $this->getZohoConnector();

                    if (! $connector) {
                        return;
                    }

                    $connector->update(['is_active' => false]);
                    $connector->delete();

                    Notification::make()
                        ->title('Zoho Invoice disconnected.')
                        ->success()
                        ->send();
                }),
            Action::make('testExportNow')
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
                            /** @var Company|null $company */
                            $company = Filament::getTenant();

                            if (! $company) {
                                return [];
                            }

                            return $company->scheduledTallyExports()
                                ->where('is_active', true)
                                ->get()
                                ->mapWithKeys(fn (ScheduledTallyExport $s) => [
                                    $s->id => $s->schedule_description.' — '.implode(', ', $s->recipient_emails),
                                ])
                                ->all();
                        })
                        ->required(),
                ])
                ->visible(function (): bool {
                    /** @var Company|null $company */
                    $company = Filament::getTenant();

                    return $company?->scheduledTallyExports()->where('is_active', true)->exists() ?? false;
                })
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
                }),
        ];
    }

    /**
     * @return array<int, Component>
     */
    protected function getIntegrationsSchema(): array
    {
        $connector = $this->getZohoConnector();

        if (! $connector) {
            return [
                TextEntry::make('zoho_status')
                    ->label('Zoho Invoice')
                    ->state('Not connected')
                    ->badge()
                    ->color('gray'),
            ];
        }

        return [
            TextEntry::make('zoho_status')
                ->label('Zoho Invoice')
                ->state('Connected')
                ->badge()
                ->color('success'),
            TextEntry::make('zoho_last_synced')
                ->label('Last Synced')
                ->state(fn (): string => $connector->last_synced_at?->setTimezone(config('app.display_timezone'))->diffForHumans() ?? 'Never'),
        ];
    }

    protected function getZohoConnector(): ?Connector
    {
        /** @var Company|null $company */
        $company = Filament::getTenant();

        return $company
            ?->connectors()
            ->where('provider', ConnectorProvider::Zoho)
            ->where('is_active', true)
            ->first();
    }
}
