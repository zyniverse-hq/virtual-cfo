<?php

namespace App\Filament\Pages\Tenancy;

use App\Enums\ConnectorProvider;
use App\Enums\ZohoDataCenter;
use App\Models\Company;
use App\Models\Connector;
use App\Services\Connectors\ZohoInvoiceService;
use App\Support\GstinValidator;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
