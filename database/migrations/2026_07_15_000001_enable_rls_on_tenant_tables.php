<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tables where company_id is NOT NULL — standard isolation policy.
     *
     * @var array<int, string>
     */
    protected array $standardTables = [
        'connectors',
        'recurring_patterns',
        'duplicate_flags',
        'budgets',
    ];

    public function up(): void
    {
        // ── Standard tables (company_id NOT NULL) ──────────────────────────
        foreach ($this->standardTables as $table) {
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

        // ── inbound_emails (company_id NULLABLE) ───────────────────────────
        //
        // Policy semantics — identical CASE expression, correct by NULL algebra:
        //
        //   context = '' or NULL  → THEN true
        //     Background ingestion webhook runs with no tenant context; it must
        //     see all rows (including company_id IS NULL for rejected/unresolved emails).
        //
        //   context = '<id>'      → company_id = <id>::bigint
        //     NULL rows evaluate as: NULL = <id> → NULL (not TRUE) → hidden.
        //     No special IS NULL clause needed — PostgreSQL handles this correctly.
        //     Company context in the Filament admin panel will only show that
        //     company's emails; rejected/unresolved emails (company_id IS NULL)
        //     remain invisible, which is the correct behaviour.
        DB::statement('ALTER TABLE inbound_emails ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE inbound_emails FORCE ROW LEVEL SECURITY');

        DB::statement("
            CREATE POLICY tenant_isolation_inbound_emails ON inbound_emails
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
        foreach ($this->standardTables as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        }

        DB::statement('DROP POLICY IF EXISTS tenant_isolation_inbound_emails ON inbound_emails');
        DB::statement('ALTER TABLE inbound_emails DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE inbound_emails NO FORCE ROW LEVEL SECURITY');
    }
};
