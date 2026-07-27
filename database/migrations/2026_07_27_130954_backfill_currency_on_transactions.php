<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('
            UPDATE transactions
            SET currency = c.currency
            FROM companies c
            WHERE transactions.company_id = c.id
            AND transactions.currency IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No down migration because we cannot reliably determine which ones were previously null
    }
};

