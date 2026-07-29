<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends tenant Row-Level Security to the tables created after the original
 * 2026_02_27 migration.
 *
 * Two tables carrying a company_id are deliberately left out, because on them the
 * column names a counterparty rather than the owning tenant:
 *
 *   company_user         A user↔company membership. Scoping it to the current
 *                        tenant collapses User::getTenants() to one row, so the
 *                        Filament tenant switcher can never leave the current
 *                        company, and Company::users() lookups (credit-card share
 *                        authorization) return nothing.
 *
 *   company_credit_card  A share record whose company_id is the *recipient*. The
 *                        owner legitimately writes rows for other companies via
 *                        CreditCard::sharedCompanies(), so an owner-only policy
 *                        rejects the share. An owner-aware policy would have to
 *                        read credit_cards, whose own policy reads this pivot —
 *                        PostgreSQL rejects mutually referencing policies with
 *                        "infinite recursion detected in policy for relation".
 *                        credit_cards keeps the sensitive columns and is still
 *                        protected, so the pivot only exposes id pairs.
 *
 * tests/Integration/Security/RowLevelSecurityTest.php pins both exemptions down.
 */
return new class extends Migration
{
    /**
     * Resolves the current tenant from the session GUC.
     */
    private const TENANT = "current_setting('app.current_company_id', true)::bigint";

    /**
     * Tables whose company_id identifies the owning tenant.
     *
     * inbound_emails belongs here despite its nullable company_id: with a tenant
     * context set, NULL = <id> evaluates to NULL rather than TRUE, so the
     * rejected/unresolved rows written by the ingestion webhook stay hidden. With
     * no context set the policy short-circuits to TRUE, so the webhook itself
     * still sees every row.
     *
     * @var array<int, string>
     */
    protected array $standardTables = [
        'connectors',
        'recurring_patterns',
        'duplicate_flags',
        'budgets',
        'invitations',
        'inbound_emails',
    ];

    public function up(): void
    {
        $ownedByTenant = $this->withoutContextAllowAll('company_id = '.self::TENANT);

        foreach ($this->standardTables as $table) {
            $this->enableRowLevelSecurity($table);

            DB::statement("
                CREATE POLICY tenant_isolation_{$table} ON {$table}
                    USING ({$ownedByTenant})
                    WITH CHECK ({$ownedByTenant})
            ");
        }

        $this->createCreditCardPolicies();
    }

    public function down(): void
    {
        foreach ($this->standardTables as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            $this->disableRowLevelSecurity($table);
        }

        DB::statement('DROP POLICY IF EXISTS tenant_shared_read_credit_cards ON credit_cards');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_credit_cards ON credit_cards');
        $this->disableRowLevelSecurity('credit_cards');
    }

    /**
     * credit_cards carries two permissive policies, which PostgreSQL ORs together
     * per command:
     *
     *   tenant_isolation_credit_cards (FOR ALL)      owner only — governs
     *       INSERT/UPDATE/DELETE and contributes the owner's rows to SELECT.
     *   tenant_shared_read_credit_cards (FOR SELECT) adds cards shared into the
     *       current tenant, keeping CreditCard::scopeVisibleToCompany() working.
     *
     * Splitting by command is the point. A company a card is shared *to* must be
     * able to read it but never modify or delete it, and a single FOR ALL policy
     * with the shared arm in USING would expose UPDATE and DELETE as well.
     */
    private function createCreditCardPolicies(): void
    {
        $this->enableRowLevelSecurity('credit_cards');

        $ownedByTenant = $this->withoutContextAllowAll('company_id = '.self::TENANT);

        DB::statement("
            CREATE POLICY tenant_isolation_credit_cards ON credit_cards
                FOR ALL
                USING ({$ownedByTenant})
                WITH CHECK ({$ownedByTenant})
        ");

        $ownedOrSharedIn = $this->withoutContextAllowAll(
            'company_id = '.self::TENANT.'
                 OR EXISTS (
                     SELECT 1
                     FROM company_credit_card ccc
                     WHERE ccc.credit_card_id = credit_cards.id
                       AND ccc.company_id = '.self::TENANT.'
                 )'
        );

        DB::statement("
            CREATE POLICY tenant_shared_read_credit_cards ON credit_cards
                FOR SELECT
                USING ({$ownedOrSharedIn})
        ");
    }

    private function enableRowLevelSecurity(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    private function disableRowLevelSecurity(string $table): void
    {
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
    }

    /**
     * Wraps a tenant predicate so it short-circuits to TRUE when no tenant context
     * is set — background jobs, the inbound-email webhook and console commands all
     * run without one.
     */
    private function withoutContextAllowAll(string $predicate): string
    {
        return "
            CASE
                WHEN current_setting('app.current_company_id', true) IS NULL
                     OR current_setting('app.current_company_id', true) = ''
                THEN true
                ELSE {$predicate}
            END
        ";
    }
};
