<?php

use App\Ai\Agents\HeadMatcher;
use App\Enums\MappingType;
use App\Enums\MatchType;
use App\Models\AccountHead;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\HeadMapping;
use App\Models\ImportedFile;
use App\Models\Transaction;
use App\Services\HeadMatcher\HeadMatcherService;
use App\Services\HeadMatcher\RuleBasedMatcher;

describe('HeadMatcherService AI matching with Agent::fake()', function () {
    it('matches unmapped transactions via AI when no rules match', function () {
        $company = Company::factory()->create();
        $head = AccountHead::factory()->create(['company_id' => $company->id, 'name' => 'Salary', 'is_active' => true]);

        $file = ImportedFile::factory()->create(['company_id' => $company->id]);
        $transaction = Transaction::factory()->unmapped()->for($file)->create([
            'description' => 'MONTHLY COMPENSATION',
            'credit' => '50000',
        ]);

        HeadMatcher::fake([
            [
                'matches' => [
                    [
                        'transaction_id' => $transaction->id,
                        'suggested_head_id' => $head->id,
                        'suggested_head_name' => 'Salary',
                        'confidence' => 0.92,
                        'reasoning' => 'Monthly compensation is salary',
                    ],
                ],
            ],
        ]);

        $service = app(HeadMatcherService::class);
        $results = $service->matchForFile($file);

        expect($results['rule_matched'])->toBe(0)
            ->and($results['ai_matched'])->toBe(1)
            ->and($results['unmatched'])->toBe(0);

        $transaction->refresh();
        expect($transaction->mapping_type)->toBe(MappingType::Ai)
            ->and($transaction->account_head_id)->toBe($head->id)
            ->and((float) $transaction->ai_confidence)->toBe(0.92);
    });

    it('does not assign head when AI returns unknown head ID', function () {
        $company = Company::factory()->create();

        $file = ImportedFile::factory()->create(['company_id' => $company->id]);
        $transaction = Transaction::factory()->unmapped()->for($file)->create([
            'description' => 'UNKNOWN PAYMENT',
            'debit' => '1000',
        ]);

        HeadMatcher::fake([
            [
                'matches' => [
                    [
                        'transaction_id' => $transaction->id,
                        'suggested_head_id' => 99999,
                        'suggested_head_name' => 'Nonexistent Head',
                        'confidence' => 0.85,
                        'reasoning' => 'Best guess',
                    ],
                ],
            ],
        ]);

        $service = app(HeadMatcherService::class);
        $results = $service->matchForFile($file);

        expect($results['ai_matched'])->toBe(0)
            ->and($results['unmatched'])->toBe(1);

        $transaction->refresh();
        expect($transaction->mapping_type)->toBe(MappingType::Unmapped)
            ->and($transaction->account_head_id)->toBeNull();
    });

    it('runs AI only on remaining unmapped after rules', function () {
        $company = Company::factory()->create();
        $salaryHead = AccountHead::factory()->create(['company_id' => $company->id, 'name' => 'Salary', 'is_active' => true]);
        $rentHead = AccountHead::factory()->create(['company_id' => $company->id, 'name' => 'Rent', 'is_active' => true]);

        HeadMapping::factory()->create([
            'company_id' => $company->id,
            'pattern' => 'SALARY',
            'match_type' => MatchType::Contains,
            'account_head_id' => $salaryHead->id,
        ]);

        $file = ImportedFile::factory()->create(['company_id' => $company->id]);
        Transaction::factory()->unmapped()->for($file)->create(['description' => 'SALARY JUNE 2024', 'credit' => '50000']);
        $rentTransaction = Transaction::factory()->unmapped()->for($file)->create(['description' => 'HOUSE RENT PAYMENT', 'debit' => '15000']);

        HeadMatcher::fake([
            [
                'matches' => [
                    [
                        'transaction_id' => $rentTransaction->id,
                        'suggested_head_id' => $rentHead->id,
                        'suggested_head_name' => 'Rent',
                        'confidence' => 0.88,
                        'reasoning' => 'Rent payment',
                    ],
                ],
            ],
        ]);

        $service = app(HeadMatcherService::class);
        $results = $service->matchForFile($file);

        expect($results['rule_matched'])->toBe(1)
            ->and($results['ai_matched'])->toBe(1)
            ->and($results['unmatched'])->toBe(0);

        HeadMatcher::assertPrompted(fn ($prompt) => $prompt->contains('HOUSE RENT PAYMENT')
            && ! $prompt->contains('SALARY'));
    });
});

describe('HeadMatcherService pseudonymization', function () {
    it('masks PII in AI prompts before sending to LLM', function () {
        $company = Company::factory()->create();
        $head = AccountHead::factory()->create(['company_id' => $company->id, 'name' => 'UPI Payment', 'is_active' => true]);

        $file = ImportedFile::factory()->create(['company_id' => $company->id]);
        $transaction = Transaction::factory()->unmapped()->for($file)->create([
            'description' => 'UPI/9876543210@okicici/PAYMENT',
            'debit' => '5000',
        ]);

        HeadMatcher::fake([
            [
                'matches' => [
                    [
                        'transaction_id' => $transaction->id,
                        'suggested_head_id' => $head->id,
                        'suggested_head_name' => 'UPI Payment',
                        'confidence' => 0.90,
                        'reasoning' => 'UPI transaction',
                    ],
                ],
            ],
        ]);

        $service = app(HeadMatcherService::class);
        $service->matchForFile($file);

        HeadMatcher::assertPrompted(function ($prompt) {
            $text = $prompt->prompt;

            return str_contains($text, '[UPI_1]')
                && ! str_contains($text, '9876543210@okicici')
                && str_contains($text, 'UPI')
                && str_contains($text, 'PAYMENT');
        });
    });

    it('preserves amounts in AI prompts (not pseudonymized)', function () {
        $company = Company::factory()->create();
        $head = AccountHead::factory()->create(['company_id' => $company->id, 'name' => 'Transfer', 'is_active' => true]);

        $file = ImportedFile::factory()->create(['company_id' => $company->id]);
        $transaction = Transaction::factory()->unmapped()->for($file)->create([
            'description' => 'NEFT TO 50100123456789',
            'debit' => '25000',
        ]);

        HeadMatcher::fake([
            [
                'matches' => [
                    [
                        'transaction_id' => $transaction->id,
                        'suggested_head_id' => $head->id,
                        'suggested_head_name' => 'Transfer',
                        'confidence' => 0.85,
                        'reasoning' => 'NEFT transfer',
                    ],
                ],
            ],
        ]);

        $service = app(HeadMatcherService::class);
        $service->matchForFile($file);

        HeadMatcher::assertPrompted(function ($prompt) {
            $text = $prompt->prompt;

            return str_contains($text, 'Debit: 25000')
                && ! str_contains($text, '50100123456789');
        });
    });
});

describe('HeadMatcherService::matchForFile()', function () {
    it('returns zeros when no unmapped transactions', function () {
        $company = Company::factory()->create();
        $file = ImportedFile::factory()->create(['company_id' => $company->id]);
        $head = AccountHead::factory()->create(['company_id' => $company->id]);
        Transaction::factory()->mapped($head)->for($file)->count(3)->create();

        $service = app(HeadMatcherService::class);
        $results = $service->matchForFile($file);

        expect($results)->toBe(['rule_matched' => 0, 'recurring_matched' => 0, 'ai_matched' => 0, 'unmatched' => 0]);
    });

    it('matches transactions using rules first', function () {
        $company = Company::factory()->create();
        $head = AccountHead::factory()->create(['company_id' => $company->id, 'name' => 'Salary']);
        HeadMapping::factory()->create([
            'company_id' => $company->id,
            'pattern' => 'SALARY',
            'match_type' => MatchType::Contains,
            'account_head_id' => $head->id,
        ]);

        $file = ImportedFile::factory()->create(['company_id' => $company->id]);
        Transaction::factory()->unmapped()->for($file)->create(['description' => 'SALARY JUNE 2024']);

        $service = app(HeadMatcherService::class);
        $results = $service->matchForFile($file);

        expect($results['rule_matched'])->toBe(1);
    });

    it('updates mapped_rows on the file after matching', function () {
        $company = Company::factory()->create();
        $head = AccountHead::factory()->create(['company_id' => $company->id]);
        HeadMapping::factory()->create([
            'company_id' => $company->id,
            'pattern' => 'EMI',
            'match_type' => MatchType::Contains,
            'account_head_id' => $head->id,
        ]);

        $file = ImportedFile::factory()->create(['company_id' => $company->id, 'mapped_rows' => 0]);
        Transaction::factory()->unmapped()->for($file)->create(['description' => 'EMI PAYMENT']);

        $service = app(HeadMatcherService::class);
        $service->matchForFile($file);

        expect($file->fresh()->mapped_rows)->toBe(1);
    });

    it('can set confidence threshold', function () {
        $service = app(HeadMatcherService::class);
        $result = $service->setConfidenceThreshold(0.5);

        expect($result)->toBeInstanceOf(HeadMatcherService::class);
    });

    it('matches many transactions using chunked rule-based matching', function () {
        $company = Company::factory()->create();
        $head1 = AccountHead::factory()->create(['company_id' => $company->id, 'name' => 'Salary']);
        $head2 = AccountHead::factory()->create(['company_id' => $company->id, 'name' => 'EMI']);

        HeadMapping::factory()->create([
            'company_id' => $company->id,
            'pattern' => 'SALARY',
            'match_type' => MatchType::Contains,
            'account_head_id' => $head1->id,
            'usage_count' => 0,
        ]);
        $emiMapping = HeadMapping::factory()->create([
            'company_id' => $company->id,
            'pattern' => 'EMI',
            'match_type' => MatchType::Contains,
            'account_head_id' => $head2->id,
            'usage_count' => 0,
        ]);

        $file = ImportedFile::factory()->create(['company_id' => $company->id]);
        Transaction::factory()->unmapped()->for($file)->count(5)->create(['description' => 'SALARY JUNE']);
        Transaction::factory()->unmapped()->for($file)->count(3)->create(['description' => 'EMI PAYMENT']);

        $service = app(HeadMatcherService::class);
        $results = $service->matchForFile($file);

        expect($results['rule_matched'])->toBe(8)
            ->and($results['unmatched'])->toBe(0)
            ->and($emiMapping->fresh()->usage_count)->toBe(3);
    });
});

describe('HeadMatcherService bank name resolution', function () {
    it('prefers bankAccount name over bank_name for rule matching', function () {
        $company = Company::factory()->create();
        $head = AccountHead::factory()->create(['company_id' => $company->id, 'name' => 'HDFC Transfer']);
        HeadMapping::factory()->forBank('HDFC Savings')->create([
            'company_id' => $company->id,
            'pattern' => 'NEFT',
            'match_type' => MatchType::Contains,
            'account_head_id' => $head->id,
        ]);

        $bankAccount = BankAccount::factory()->create(['name' => 'HDFC Savings']);
        $file = ImportedFile::factory()->create([
            'company_id' => $company->id,
            'bank_name' => 'HDFC Bank',
            'bank_account_id' => $bankAccount->id,
        ]);
        Transaction::factory()->unmapped()->for($file)->create(['description' => 'NEFT TRANSFER']);

        // Fake AI agent so it doesn't make real API calls
        HeadMatcher::fake([['matches' => []]]);

        $service = app(HeadMatcherService::class);
        $results = $service->matchForFile($file);

        // Should match because bankAccount->name is 'HDFC Savings', not 'HDFC Bank'
        expect($results['rule_matched'])->toBe(1);
    });

    it('falls back to bank_name when no bankAccount is linked', function () {
        $company = Company::factory()->create();
        $head = AccountHead::factory()->create(['company_id' => $company->id, 'name' => 'SBI Transfer']);
        HeadMapping::factory()->forBank('SBI')->create([
            'company_id' => $company->id,
            'pattern' => 'NEFT',
            'match_type' => MatchType::Contains,
            'account_head_id' => $head->id,
        ]);

        $file = ImportedFile::factory()->create([
            'company_id' => $company->id,
            'bank_name' => 'SBI',
            'bank_account_id' => null,
        ]);
        Transaction::factory()->unmapped()->for($file)->create(['description' => 'NEFT TRANSFER']);

        $service = app(HeadMatcherService::class);
        $results = $service->matchForFile($file);

        expect($results['rule_matched'])->toBe(1);
    });
});

describe('HeadMatcherService::resolveAccountHead()', function () {
    it('resolves account head by ID', function () {
        $head = AccountHead::factory()->create();
        $service = new HeadMatcherService(new RuleBasedMatcher);

        $method = new ReflectionMethod($service, 'resolveAccountHead');
        $result = $method->invoke($service, [
            'suggested_head_id' => $head->id,
            'suggested_head_name' => 'Wrong Name',
        ], $head->company_id);

        expect($result->id)->toBe($head->id);
    });

    it('falls back to name when ID not found', function () {
        $head = AccountHead::factory()->create(['name' => 'Salary']);
        $service = new HeadMatcherService(new RuleBasedMatcher);

        $method = new ReflectionMethod($service, 'resolveAccountHead');
        $result = $method->invoke($service, [
            'suggested_head_id' => 99999,
            'suggested_head_name' => 'Salary',
        ], $head->company_id);

        expect($result->id)->toBe($head->id);
    });

    it('returns null when neither ID nor name matches', function () {
        $company = Company::factory()->create();
        $service = new HeadMatcherService(new RuleBasedMatcher);

        $method = new ReflectionMethod($service, 'resolveAccountHead');
        $result = $method->invoke($service, [
            'suggested_head_id' => 99999,
            'suggested_head_name' => 'Nonexistent Head',
        ], $company->id);

        expect($result)->toBeNull();
    });

    it('name fallback normalizes whitespace in suggested_head_name before querying', function () {
        $head = AccountHead::factory()->create(['name' => 'Internet Expense']);
        $service = new HeadMatcherService(new RuleBasedMatcher);

        $method = new ReflectionMethod($service, 'resolveAccountHead');

        // LLM returns the name with a trailing newline — ID is wrong to force the name fallback
        $result = $method->invoke($service, [
            'suggested_head_id' => 99999,
            'suggested_head_name' => "Internet Expense\n",
        ], $head->company_id);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($head->id);
    });

    it('name fallback normalizes double internal spaces in suggested_head_name', function () {
        $head = AccountHead::factory()->create(['name' => 'Godaddy - Subscription']);
        $service = new HeadMatcherService(new RuleBasedMatcher);

        $method = new ReflectionMethod($service, 'resolveAccountHead');

        $result = $method->invoke($service, [
            'suggested_head_id' => 99999,
            'suggested_head_name' => 'Godaddy  - Subscription',
        ], $head->company_id);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($head->id);
    });
});

describe('HeadMatcherService company isolation', function () {
    it('rule-based matching only uses mappings from the same company', function () {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $headA = AccountHead::factory()->create(['company_id' => $companyA->id, 'name' => 'Salary A', 'is_active' => true]);
        AccountHead::factory()->create(['company_id' => $companyB->id, 'name' => 'Salary B', 'is_active' => true]);

        // Only company B has a mapping rule
        $headB2 = AccountHead::factory()->create(['company_id' => $companyB->id, 'name' => 'Office Expenses', 'is_active' => true]);
        HeadMapping::factory()->create([
            'company_id' => $companyB->id,
            'pattern' => 'OFFICE',
            'match_type' => MatchType::Contains,
            'account_head_id' => $headB2->id,
        ]);

        // Company A file should NOT match using company B's rules
        $fileA = ImportedFile::factory()->create(['company_id' => $companyA->id]);
        $transaction = Transaction::factory()->unmapped()->for($fileA)->create([
            'description' => 'OFFICE SUPPLIES',
            'debit' => '1000',
        ]);

        HeadMatcher::fake([['matches' => []]]);

        $service = app(HeadMatcherService::class);
        $results = $service->matchForFile($fileA);

        expect($results['rule_matched'])->toBe(0);
        $transaction->refresh();
        expect($transaction->mapping_type)->toBe(MappingType::Unmapped);
    });

    it('AI matching only uses account heads from the same company', function () {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        // Company B has a head that company A does not have
        $headB = AccountHead::factory()->create(['company_id' => $companyB->id, 'name' => 'CompanyB Expenses', 'is_active' => true]);

        $fileA = ImportedFile::factory()->create(['company_id' => $companyA->id]);
        $transaction = Transaction::factory()->unmapped()->for($fileA)->create([
            'description' => 'MONTHLY SALARY',
            'credit' => '50000',
        ]);

        // AI suggests company B's head ID and name — neither should match for company A
        HeadMatcher::fake([
            [
                'matches' => [
                    [
                        'transaction_id' => $transaction->id,
                        'suggested_head_id' => $headB->id,
                        'suggested_head_name' => 'CompanyB Expenses',
                        'confidence' => 0.95,
                        'reasoning' => 'Cross-company suggestion',
                    ],
                ],
            ],
        ]);

        $service = app(HeadMatcherService::class);
        $results = $service->matchForFile($fileA);

        // Should not match — head belongs to company B, not company A
        expect($results['ai_matched'])->toBe(0);
        $transaction->refresh();
        expect($transaction->mapping_type)->toBe(MappingType::Unmapped);
    });

    it('AI matching resolves account head by name only within the same company', function () {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $headA = AccountHead::factory()->create(['company_id' => $companyA->id, 'name' => 'Rent', 'is_active' => true]);
        AccountHead::factory()->create(['company_id' => $companyB->id, 'name' => 'Rent', 'is_active' => true]);

        $fileA = ImportedFile::factory()->create(['company_id' => $companyA->id]);
        $transaction = Transaction::factory()->unmapped()->for($fileA)->create([
            'description' => 'HOUSE RENT',
            'debit' => '20000',
        ]);

        // AI returns unknown ID but correct name — name fallback must scope to company A
        HeadMatcher::fake([
            [
                'matches' => [
                    [
                        'transaction_id' => $transaction->id,
                        'suggested_head_id' => 99999,
                        'suggested_head_name' => 'Rent',
                        'confidence' => 0.90,
                        'reasoning' => 'Rent payment',
                    ],
                ],
            ],
        ]);

        $service = app(HeadMatcherService::class);
        $results = $service->matchForFile($fileA);

        expect($results['ai_matched'])->toBe(1);
        $transaction->refresh();
        expect($transaction->account_head_id)->toBe($headA->id);
    });
});
