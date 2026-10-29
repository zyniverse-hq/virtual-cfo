<?php

use App\Filament\Resources\CashTransactions\CashTransactionResource;
use App\Filament\Resources\CashTransactions\Pages\ManageCashTransactions;
use App\Models\CashTransaction;
use Carbon\Carbon;
use Filament\Actions\CreateAction;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = asUser();
    $this->company = tenant();
});

describe('CashTransactionResource', function () {
    it('can render the index page', function () {
        $this->get(CashTransactionResource::getUrl('index'))
            ->assertSuccessful();
    });

    it('can list cash transactions', function () {
        CashTransaction::factory()->count(3)->create(['company_id' => $this->company->id]);

        livewire(ManageCashTransactions::class)
            ->assertCanSeeTableRecords(CashTransaction::all());
    });

    it('can create a cash transaction', function () {
        $date = Carbon::now()->format('Y-m-d');
        livewire(ManageCashTransactions::class)
            ->callAction(CreateAction::class, data: [
                'date' => $date,
                'description' => 'Office Supplies',
                'amount' => 150.50,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('cash_transactions', [
            'description' => 'Office Supplies',
            'amount' => 150.50,
        ]);
    });
});
