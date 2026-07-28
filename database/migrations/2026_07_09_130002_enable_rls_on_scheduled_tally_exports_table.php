<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $table = 'scheduled_tally_exports';
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

        DB::statement("
            CREATE POLICY tenant_isolation_{$table} ON {$table}
                USING (
                    CASE
                        WHEN current_setting('app.current_company_id', true) IS NULL
                             OR current_setting('app.current_company_id', true) = ''
                        THEN true
                        ELSE company_id = current_setting('app.current_company_id', true)::bigint
                    END
                )
                WITH CHECK (
                    CASE
                        WHEN current_setting('app.current_company_id', true) IS NULL
                             OR current_setting('app.current_company_id', true) = ''
                        THEN true
                        ELSE company_id = current_setting('app.current_company_id', true)::bigint
                    END
                )
        ");
    }

    public function down(): void
    {
        $table = 'scheduled_tally_exports';
        DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
    }
};
