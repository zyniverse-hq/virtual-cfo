<?php

use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Fill missing transaction currencies from their company's currency.
     * Rows that already have a currency are never touched.
     */
    public function up(): void
    {
        Company::query()->each(function (Company $company): void {
            Transaction::withTrashed()
                ->where('company_id', $company->id)
                ->whereNull('currency')
                ->update(['currency' => $company->currency]);
        });
    }

    /**
     * Data backfill only: the previously-null rows cannot be identified afterwards, so there is nothing to reverse.
     */
    public function down(): void {}
};
