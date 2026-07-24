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

    it('enforces RLS on company_credit_card table', function () {
        $cardA = CreditCard::factory()->for($this->companyA)->create();
        $cardB = CreditCard::factory()->for($this->companyB)->create();
        $sharer = User::factory()->create();

        DB::table('company_credit_card')->insert([
            ['company_id' => $this->companyA->id, 'credit_card_id' => $cardA->id, 'shared_by' => $sharer->id, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $this->companyB->id, 'credit_card_id' => $cardB->id, 'shared_by' => $sharer->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::unprepared("SET app.current_company_id = '{$this->companyA->id}'");
        DB::unprepared('SET ROLE rls_test_user');

        expect(DB::table('company_credit_card')->count())->toBe(1);
    });
});
