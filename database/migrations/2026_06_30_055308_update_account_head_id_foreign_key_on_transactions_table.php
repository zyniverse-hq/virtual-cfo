<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['account_head_id']);
            $table->foreign('account_head_id')
                ->references('id')
                ->on('account_heads')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['account_head_id']);
            $table->foreign('account_head_id')
                ->references('id')
                ->on('account_heads')
                ->nullOnDelete();
        });
    }
};
