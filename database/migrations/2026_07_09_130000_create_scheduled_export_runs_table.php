<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_export_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_tally_export_id')->nullable()->constrained('scheduled_tally_exports')->nullOnDelete();
            $table->text('status');
            $table->unsignedInteger('transactions_count')->default(0);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->jsonb('recipients');
            $table->text('error_message')->nullable();
            $table->text('triggered_by')->default('scheduler');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['company_id', 'created_at']);
            $table->index('scheduled_tally_export_id');
        });

        DB::statement("ALTER TABLE scheduled_export_runs ADD CONSTRAINT scheduled_export_runs_recipients_check CHECK (jsonb_typeof(recipients) = 'array')");
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_export_runs');
    }
};
