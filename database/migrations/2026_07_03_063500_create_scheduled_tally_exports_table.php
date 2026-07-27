<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_tally_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('frequency');
            $table->smallInteger('day_of_week')->nullable();
            $table->smallInteger('day_of_month')->nullable();
            $table->time('time_of_day')->default('10:00');
            $table->text('timezone')->default('Asia/Kolkata');
            $table->text('date_range_window');
            $table->text('statement_type')->nullable();
            $table->text('recipient_emails');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_run_at')->nullable();
            $table->text('last_run_status')->nullable();
            $table->text('last_run_message')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('company_id');
            $table->index(['is_active', 'frequency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_tally_exports');
    }
};
