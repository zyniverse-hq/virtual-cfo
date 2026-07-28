<?php

use App\Models\AccountHead;
use App\Models\BankAccount;
use App\Models\Budget;
use App\Models\Company;
use App\Models\Connector;
use App\Models\CreditCard;
use App\Models\DuplicateFlag;
use App\Models\HeadMapping;
use App\Models\ImportedFile;
use App\Models\InboundEmail;
use App\Models\Invitation;
use App\Models\RecurringPattern;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * RLS tests run in the Integration suite (no LazilyRefreshDatabase)
 * to avoid transaction wrapping that conflicts with SET ROLE.
 * Data is created via factories (committed) and cleaned up manually.
 */
describe('Row-Level Security', function () {
    beforeEach(function () {
        DB::statement("DO $$ BEGIN
            IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'rls_test_user') THEN
                CREATE ROLE rls_test_user NOLOGIN;
            END IF;
        END $$");
        DB::statement('GRANT USAGE ON SCHEMA public TO rls_test_user');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO rls_test_user');
        DB::statement('GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO rls_test_user');

        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
    });

    afterEach(function () {
        try {
            DB::unprepared('RESET ROLE');
        } catch (Throwable) {
        }

        try {
            DB::unprepared("SET app.current_company_id = ''");
        } catch (Throwable) {
        }

        // DuplicateFlag before Transaction (references transaction_id FK)
        DuplicateFlag::withoutGlobalScopes()->whereIn('company_id', [$this->companyA->id, $this->companyB->id])->delete();
        Transaction::withoutGlobalScopes()->whereIn('company_id', [$this->companyA->id, $this->companyB->id])->forceDelete();
        HeadMapping::withoutGlobalScopes()->whereIn('company_id', [$this->companyA->id, $this->companyB->id])->forceDelete();
        // Budget before AccountHead (references account_head_id FK)
        Budget::withoutGlobalScopes()->whereIn('company_id', [$this->companyA->id, $this->companyB->id])->delete();
        AccountHead::withoutGlobalScopes()->whereIn('company_id', [$this->companyA->id, $this->companyB->id])->forceDelete();
        BankAccount::withoutGlobalScopes()->whereIn('company_id', [$this->companyA->id, $this->companyB->id])->forceDelete();
        ImportedFile::withoutGlobalScopes()->whereIn('company_id', [$this->companyA->id, $this->companyB->id])->forceDelete();
        // Connector has SoftDeletes — use forceDelete
        Connector::withoutGlobalScopes()->whereIn('company_id', [$this->companyA->id, $this->companyB->id])->forceDelete();
        RecurringPattern::withoutGlobalScopes()->whereIn('company_id', [$this->companyA->id, $this->companyB->id])->delete();
        // InboundEmail cleanup includes null company_id (rejected emails)
        InboundEmail::withoutGlobalScopes()
            ->whereIn('company_id', [$this->companyA->id, $this->companyB->id])
            ->orWhereNull('company_id')
            ->delete();
        Invitation::whereIn('company_id', [$this->companyA->id, $this->companyB->id])->delete();
        // company_credit_card pivot before credit_cards (references credit_card_id FK)
        DB::table('company_credit_card')->whereIn('company_id', [$this->companyA->id, $this->companyB->id])->delete();
        DB::table('company_user')->whereIn('company_id', [$this->companyA->id, $this->companyB->id])->delete();
        CreditCard::withoutGlobalScopes()->whereIn('company_id', [$this->companyA->id, $this->companyB->id])->forceDelete();
        $this->companyA->forceDelete();
        $this->companyB->forceDelete();
    });

    it('shows all rows when no tenant context is set', function () {
        $fileA = ImportedFile::factory()->for($this->companyA)->create();
        $fileB = ImportedFile::factory()->for($this->companyB)->create();
        Transaction::factory()->for($fileA)->create(['company_id' => $this->companyA->id]);
        Transaction::factory()->for($fileB)->create(['company_id' => $this->companyB->id]);

        DB::unprepared('SET ROLE rls_test_user');
        $count = DB::table('transactions')
            ->whereIn('company_id', [$this->companyA->id, $this->companyB->id])
            ->count();

        expect($count)->toBe(2);
    });

    it('filters transactions by company when tenant context is set', function () {
        $fileA = ImportedFile::factory()->for($this->companyA)->create();
        $fileB = ImportedFile::factory()->for($this->companyB)->create();
        Transaction::factory()->for($fileA)->create(['company_id' => $this->companyA->id]);
        Transaction::factory()->for($fileB)->create(['company_id' => $this->companyB->id]);

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        $count = DB::table('transactions')->count();

        expect($count)->toBe(1);
    });

    it('company A cannot see company B transactions', function () {
        $fileA = ImportedFile::factory()->for($this->companyA)->create();
        $fileB = ImportedFile::factory()->for($this->companyB)->create();

        $txA = Transaction::factory()->for($fileA)->create(['company_id' => $this->companyA->id]);
        $txB = Transaction::factory()->for($fileB)->create(['company_id' => $this->companyB->id]);

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        $visibleIds = DB::table('transactions')->pluck('id')->toArray();

        expect($visibleIds)->toContain($txA->id)
            ->and($visibleIds)->not->toContain($txB->id);
    });

    it('enforces RLS on imported_files table', function () {
        ImportedFile::factory()->for($this->companyA)->create();
        ImportedFile::factory()->for($this->companyB)->create();

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        expect(DB::table('imported_files')->count())->toBe(1);
    });

    it('enforces RLS on account_heads table', function () {
        AccountHead::factory()->for($this->companyA)->create();
        AccountHead::factory()->for($this->companyB)->create();

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        expect(DB::table('account_heads')->count())->toBe(1);
    });

    it('enforces RLS on head_mappings table', function () {
        HeadMapping::factory()->for($this->companyA)->create();
        HeadMapping::factory()->for($this->companyB)->create();

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        expect(DB::table('head_mappings')->count())->toBe(1);
    });

    it('enforces RLS on bank_accounts table', function () {
        BankAccount::factory()->for($this->companyA)->create();
        BankAccount::factory()->for($this->companyB)->create();

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        expect(DB::table('bank_accounts')->count())->toBe(1);
    });

    it('blocks INSERT into wrong tenant via WITH CHECK', function () {
        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');
        DB::beginTransaction();

        $threw = false;

        try {
            DB::table('account_heads')->insert([
                'name' => 'Salary',
                'company_id' => $this->companyB->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $threw = true;
        }

        DB::rollBack();

        expect($threw)->toBeTrue();
    });

    // ── New tables added after Feb 27 2026 (Issue #315) ───────────────────

    it('enforces RLS on connectors table', function () {
        Connector::factory()->for($this->companyA)->create();
        Connector::factory()->for($this->companyB)->create();

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        expect(DB::table('connectors')->count())->toBe(1);
    });

    it('enforces RLS on recurring_patterns table', function () {
        RecurringPattern::factory()->for($this->companyA)->create();
        RecurringPattern::factory()->for($this->companyB)->create();

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        expect(DB::table('recurring_patterns')->count())->toBe(1);
    });

    it('enforces RLS on duplicate_flags table', function () {
        // Create ImportedFile + Transaction pairs for each company explicitly so
        // that afterEach cleanup (whereIn company_id) catches everything.
        $fileA = ImportedFile::factory()->for($this->companyA)->create();
        $fileB = ImportedFile::factory()->for($this->companyB)->create();

        $txA1 = Transaction::factory()->for($fileA)->create(['company_id' => $this->companyA->id]);
        $txA2 = Transaction::factory()->for($fileA)->create(['company_id' => $this->companyA->id]);
        $txB1 = Transaction::factory()->for($fileB)->create(['company_id' => $this->companyB->id]);
        $txB2 = Transaction::factory()->for($fileB)->create(['company_id' => $this->companyB->id]);

        DuplicateFlag::factory()->create([
            'company_id' => $this->companyA->id,
            'transaction_id' => $txA1->id,
            'duplicate_transaction_id' => $txA2->id,
        ]);
        DuplicateFlag::factory()->create([
            'company_id' => $this->companyB->id,
            'transaction_id' => $txB1->id,
            'duplicate_transaction_id' => $txB2->id,
        ]);

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        expect(DB::table('duplicate_flags')->count())->toBe(1);
    });

    it('enforces RLS on budgets table', function () {
        // Create AccountHead explicitly per company so Budget records stay under
        // the correct company_id and are cleaned up by the afterEach.
        $headA = AccountHead::factory()->for($this->companyA)->create();
        $headB = AccountHead::factory()->for($this->companyB)->create();

        Budget::factory()->create([
            'company_id' => $this->companyA->id,
            'account_head_id' => $headA->id,
        ]);
        Budget::factory()->create([
            'company_id' => $this->companyB->id,
            'account_head_id' => $headB->id,
        ]);

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        expect(DB::table('budgets')->count())->toBe(1);
    });

    it('enforces RLS on inbound_emails table', function () {
        InboundEmail::factory()->for($this->companyA)->create();
        InboundEmail::factory()->for($this->companyB)->create();

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        expect(DB::table('inbound_emails')->count())->toBe(1);
    });

    it('hides null company_id inbound_emails when tenant context is set', function () {
        // Rejected email (unknown inbox address) — company_id IS NULL
        InboundEmail::factory()->rejected()->create(['recipient' => 'unknown@inbox.example.com']);
        // Valid email for companyA
        InboundEmail::factory()->for($this->companyA)->create();

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        // NULL = <id>::bigint evaluates to NULL (not TRUE) → rejected email is hidden.
        // Only the companyA email is visible.
        expect(DB::table('inbound_emails')->count())->toBe(1);
    });

    it('shows null company_id inbound_emails when no tenant context is set', function () {
        // Rejected email (unknown inbox address) — company_id IS NULL
        InboundEmail::factory()->rejected()->create(['recipient' => 'unknown@inbox.example.com']);
        // Valid email for companyA
        InboundEmail::factory()->for($this->companyA)->create();

        // No SET context — background webhook scenario
        DB::unprepared('SET ROLE rls_test_user');

        // Empty context → policy THEN true → all rows visible including NULL rows.
        expect(DB::table('inbound_emails')->count())->toBe(2);
    });

    it('enforces RLS on invitations table', function () {
        Invitation::factory()->for($this->companyA)->create();
        Invitation::factory()->for($this->companyB)->create();

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        expect(DB::table('invitations')->count())->toBe(1);
    });

    // ── Deliberately exempt mapping tables ────────────────────────────────
    //
    // company_user and company_credit_card both carry a company_id, but on both it
    // names a counterparty rather than the owning tenant. Scoping them breaks
    // features that must read across tenants, so they are exempt by design. These
    // tests fail if someone puts RLS back on either table.

    it('keeps a users full company membership visible under a tenant context', function () {
        $user = User::factory()->create();
        $this->companyA->users()->attach($user);
        $this->companyB->users()->attach($user);

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        // User::getTenants() reads this join to build the Filament tenant switcher.
        // Scoping company_user would strip companyB and trap the user in companyA.
        $companyIds = DB::table('company_user')
            ->where('user_id', $user->id)
            ->pluck('company_id')
            ->all();

        expect($companyIds)->toContain($this->companyA->id)
            ->and($companyIds)->toContain($this->companyB->id);
    });

    it('allows an owner to share a card into another company', function () {
        $cardA = CreditCard::factory()->for($this->companyA)->create();
        $sharer = User::factory()->create();

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        // CreditCardResource's share action writes company_id = the *recipient*
        // while the acting tenant is the owner, via
        // sharedCompanies()->syncWithoutDetaching(). An owner-only WITH CHECK on
        // this pivot would reject the whole feature.
        DB::table('company_credit_card')->insert([
            'company_id' => $this->companyB->id,
            'credit_card_id' => $cardA->id,
            'shared_by' => $sharer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::table('company_credit_card')->where('credit_card_id', $cardA->id)->count())->toBe(1);
    });

    it('keeps share rows for owned cards visible to the owner', function () {
        $cardA = CreditCard::factory()->for($this->companyA)->create();
        $sharer = User::factory()->create();

        DB::table('company_credit_card')->insert([
            'company_id' => $this->companyB->id,
            'credit_card_id' => $cardA->id,
            'shared_by' => $sharer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        // Drives the "Shared With" counter on CreditCardResource, which counts
        // pivot rows whose company_id is another company.
        expect(DB::table('company_credit_card')->where('credit_card_id', $cardA->id)->count())->toBe(1);
    });

    it('allows reading shared credit_cards via custom USING policy', function () {
        $cardA = CreditCard::factory()->for($this->companyA)->create();
        $cardB = CreditCard::factory()->for($this->companyB)->create();

        $sharer = User::factory()->create();
        DB::table('company_credit_card')->insert([
            'company_id' => $this->companyB->id,
            'credit_card_id' => $cardA->id,
            'shared_by' => $sharer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::unprepared("SET app.current_company_id = '{$this->companyB->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        $visibleIds = DB::table('credit_cards')->pluck('id')->toArray();
        expect($visibleIds)->toContain($cardA->id)
            ->and($visibleIds)->toContain($cardB->id)
            ->and(count($visibleIds))->toBe(2);
    });

    it('does not let a company modify a card that is only shared with it', function () {
        $cardA = CreditCard::factory()->for($this->companyA)->create(['name' => 'Owner name']);
        $sharer = User::factory()->create();

        DB::table('company_credit_card')->insert([
            'company_id' => $this->companyB->id,
            'credit_card_id' => $cardA->id,
            'shared_by' => $sharer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::unprepared("SET app.current_company_id = '{$this->companyB->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        // tenant_shared_read_credit_cards exposes the row for reading, but
        // tenant_isolation_credit_cards keeps writes owner-only, so the UPDATE
        // matches no rows instead of mutating another company's card.
        $affected = DB::table('credit_cards')->where('id', $cardA->id)->update(['name' => 'Hijacked']);

        expect($affected)->toBe(0);

        DB::unprepared('RESET ROLE');
        DB::unprepared("SET app.current_company_id = ''");

        expect(DB::table('credit_cards')->where('id', $cardA->id)->value('name'))->toBe('Owner name');
    });

    it('blocks cross-tenant INSERT via WITH CHECK on every protected table', function () {
        // FK targets and NOT NULL values have to be genuinely valid, otherwise the
        // insert fails on the schema instead of on the policy and the assertion
        // proves nothing. These are created before any tenant context is set.
        $headB = AccountHead::factory()->for($this->companyB)->create();
        $fileB = ImportedFile::factory()->for($this->companyB)->create();
        $txB1 = Transaction::factory()->for($fileB)->create(['company_id' => $this->companyB->id]);
        $txB2 = Transaction::factory()->for($fileB)->create(['company_id' => $this->companyB->id]);
        $inviter = User::factory()->create();

        $payloads = [
            'connectors' => [
                'provider' => 'zoho',
            ],
            'recurring_patterns' => [
                'description_pattern' => 'ACME RENT',
            ],
            'duplicate_flags' => [
                'transaction_id' => $txB1->id,
                'duplicate_transaction_id' => $txB2->id,
                'confidence' => 'high',
                'match_reasons' => '[]',
            ],
            'budgets' => [
                'account_head_id' => $headB->id,
                'period_type' => 'monthly',
                'amount' => 1000,
                'financial_year' => '2025-26',
            ],
            'inbound_emails' => [
                'recipient' => 'inbox@example.com',
                'status' => 'received',
                'received_at' => now(),
            ],
            'invitations' => [
                'email' => 'invitee@example.com',
                'role' => 'viewer',
                'token' => 'rls-with-check-probe',
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDay(),
            ],
            'credit_cards' => [
                'name' => 'Cross tenant card',
                'is_active' => true,
            ],
        ];

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        foreach ($payloads as $table => $payload) {
            DB::beginTransaction();
            $error = null;

            try {
                DB::table($table)->insert([
                    ...$payload,
                    'company_id' => $this->companyB->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                $error = $e->getMessage();
            }

            DB::rollBack();

            expect($error)->not->toBeNull("Cross-tenant INSERT into {$table} was not rejected")
                ->and($error)->toContain('violates row-level security policy');
        }
    });

    // ── Coverage Guard ────────────────────────────────────────────────────────

    it('has row-level security enabled and forced on every tenant-scoped table', function () {
        // A company_id column alone does not make a table tenant-scoped. On these
        // two it names a counterparty, and scoping them breaks the tenant switcher
        // and credit-card sharing — see the exemption tests above. Anything else
        // that grows a company_id has to be added to the migration, not here.
        $exempt = ['company_user', 'company_credit_card'];

        // Joined through pg_attribute rather than information_schema so the filter
        // stays on one catalog: relkind = 'r' excludes views and indexes, and the
        // namespace comes from the same row as the table, so nothing duplicates.
        $tables = DB::select(<<<'SQL'
            SELECT c.relname AS table_name,
                   c.relrowsecurity,
                   c.relforcerowsecurity
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid
            WHERE n.nspname = 'public'
              AND c.relkind = 'r'
              AND a.attname = 'company_id'
              AND a.attnum > 0
              AND NOT a.attisdropped
            ORDER BY c.relname
        SQL);

        expect($tables)->not->toBeEmpty();

        $unprotected = [];

        foreach ($tables as $table) {
            if (in_array($table->table_name, $exempt, true)) {
                expect($table->relrowsecurity)
                    ->toBeFalse("{$table->table_name} is exempt from RLS by design but has it enabled");

                continue;
            }

            if (! $table->relrowsecurity || ! $table->relforcerowsecurity) {
                $unprotected[] = $table->table_name;
            }
        }

        expect($unprotected)->toBe([], 'Tables with a company_id and no forced RLS: '.implode(', ', $unprotected));
    });
});
