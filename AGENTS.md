# Agent Instructions

These instructions are auto-loaded for every subagent. Follow them exactly.

---

## Hard Gate: MCP Pre-flight (Step 0)

**Before ANY other work**, verify Laravel Boost MCP tools are available:

```
Run: ToolSearch for "mcp__laravel-boost"
```

- If tools respond: proceed
- If tools are unavailable: **STOP IMMEDIATELY**. Report: "MCP tools unavailable — cannot proceed." Do NOT continue development without them.

**No retries.** MCP availability is a binary gate.

---

## Progress Tracking Protocol

At the start of every task, create a TaskCreate checklist with one item per workflow step. Update each item to `completed` as you finish it. **Do NOT proceed to the commit step unless ALL prior items are completed.**

Example:
```
TaskCreate: "Step 0: MCP Pre-flight"
TaskCreate: "Step 1: Understand issue"
TaskCreate: "Step 2: Branch"
... (one per step)
```

Update each with `TaskUpdate` as you complete it. If a step fails and you cannot resolve it within retry limits, update the task with the failure reason and STOP.

---

## Retry Limits & Escape Hatches

When a step fails, diagnose first: **is this an environment error or a code error?**

- **Environment error** (wrong DB credentials, missing table, MCP down, composer not installed): **STOP and report.** Do not attempt code fixes for environment problems.
- **Code error** (test fails, PHPStan error, validation issue): Fix and retry up to the limit below.

| Gate | Max Retries | On Limit Reached |
|------|-------------|------------------|
| MCP Pre-flight | 0 | STOP, report |
| Worktree env setup | 1 | STOP, report env issue |
| PHPStan fixes | 3 | STOP, report remaining errors with file:line |
| Test failures (GREEN phase) | 3 | STOP, report which tests fail and what approaches were tried |
| Spec review rounds | 2 | Accept current coverage, note gaps in issue comment |
| CI check full cycle | 3 | STOP, report state |
| Pint formatting | 2 | STOP, report conflict |

**After reaching any limit:** STOP and report. Include:
1. What failed (exact error output)
2. What was tried (each attempt)
3. Your diagnosis (environment vs code, root cause theory)

**Never** try creative workarounds after hitting a limit. Report and let the orchestrator or user decide.

---

## Hooks Awareness

These hooks run automatically — you do NOT need to run them manually. Registered in `.claude/settings.json`:

| Hook | Trigger | What it does |
|------|---------|-------------|
| `protect-files.sh` | Before Edit/Write | Blocks edits to `composer.lock`, `phpunit.xml`, `docs/schema/*.sql` |
| `format-php.sh` | After Edit/Write | Auto-runs Pint formatting |
| `phpstan-check.sh` | After Edit/Write of `.php` outside `tests/` | Runs PHPStan level 6 |

**Do not panic** if you see auto-formatting changes after editing PHP. That is the Pint hook. Do not undo these changes.

`.claude/hooks/block-dangerous-commands.sh` exists but is **not registered** in `settings.json` — it does not run. Do not rely on it to catch `rm -rf`, `git reset --hard`, or force pushes.

`.env` is **not** protected by a hook. Never edit it without being asked.

### Pre-commit verification

Run `bash bin/ci-check.sh` before every commit. It mirrors the gates in
`.github/workflows/tests.yml` — syntax, Pint, PHPStan, `composer audit`, and the
unit/architecture/feature suites — and runs every gate even after one fails, so
you see all problems in one pass.

```bash
bash bin/ci-check.sh                     # all gates
bash bin/ci-check.sh --filter=UserTest   # scope the test gates while iterating
bash bin/ci-check.sh --skip-tests        # static gates only (fast)
bash bin/ci-check.sh --coverage          # also enforce CI's coverage minimums
```

Coverage minimums are off by default because they need a coverage driver and are
meaningless on a filtered subset. CI always enforces them.

**`composer audit` currently fails on `master`** (symfony advisories). That is
pre-existing — confirm a failure is yours before chasing it.

---

## Worktree Environment Setup

When working in an isolated worktree, complete these steps **before** starting implementation:

```bash
# 1. Install dependencies (allow 3-5 min on Windows)
composer install --no-interaction

# 2. Copy environment file
cp .env.example .env

# 3. Generate application key
php artisan key:generate

# 4. Set the PostgreSQL credentials in .env — ONE connection:
#    DB_CONNECTION=pgsql
#    DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD

# 5. Clear config cache
php artisan config:clear

# 6. Do NOT run `git checkout master` — the worktree is already based on master
```

**Do not symlink `vendor/`** into a worktree. Composer's `$baseDir` then resolves `App\` to the main checkout, so you silently test `master`'s code instead of the branch's. Run `composer install` in the worktree, or copy `vendor/` outright.

**Test database** (`phpunit.xml`): `virtual_cfo_test` on the `pgsql` connection. If tests fail with "unknown database", create it in PostgreSQL first — that is an environment error, not a code error.

**Use your own test database when running in parallel with other agents.** The shared `virtual_cfo_test` deadlocks and throws misleading `relation "companies" does not exist` errors when two `migrate:fresh` runs overlap. Set `DB_DATABASE=virtual_cfo_test_<something-unique>` and drop it when done.

**The full suite is order-dependent and currently fails on `master`.** `LazilyRefreshDatabase` (`tests/Pest.php`) applies only to `Feature`; `tests/Integration` gets no database trait, so one aborted transaction tears down the schema for the rest of the process. Verify with targeted `--filter` runs. A whole-suite failure is not by itself evidence that your change broke something — reproduce it on `master` before believing it.

**`tests/Integration` never runs in CI** (`.github/workflows/tests.yml` runs only `tests/Unit`, `tests/Architecture`, and `pest tests/Feature`). Tests you add there will not gate any PR.

---

## Key Coding Rules

These are the most critical rules from `.claude/rules/`. The full rule files auto-load based on file path when you edit files — consult them for complete details.

This is a **Filament v5 admin panel**, not a REST API — `routes/api.php` has two routes (the Mailgun inbound-email webhook). Almost all behaviour lives in Filament resources and Livewire components, so write tests against those, not HTTP status codes.

### Validation
- **Always** use Form Request classes — never `$request->validate()` inline
- Authorize via Form Request `authorize()` or a Policy

### Tests
- Use Pest `describe`/`it` blocks — never `test()`
- Use factories for database records — never mock models
- Never write `assertOk()`-only tests, no config-value assertions, no Reflection-based tests
- Filament tests must assert real behaviour: form fields, table records, or visible content — `livewire(ListX::class)` renders header widgets and footers too
- Testing diamond: ~5% static, ~25% unit/arch, ~65% integration, ~5% E2E

### PostgreSQL migrations (non-negotiable)
`TEXT` not `VARCHAR(n)` · `TIMESTAMPTZ` not `TIMESTAMP` · `BIGINT GENERATED ALWAYS AS IDENTITY` not `SERIAL` · `JSONB` with a CHECK constraint not `JSON` · **explicit FK indexes** (PostgreSQL does not create them) · partial unique `WHERE deleted_at IS NULL` with soft deletes

### Multi-tenancy — read before touching tenant data
- Several tables have Row Level Security, but **it is not enforced in practice**: the app connects as `postgres` (a superuser, which bypasses RLS unconditionally, even under `FORCE`), and `SetTenantDatabaseContext` is registered via `authMiddleware()` without `isPersistent: true`, so it never runs on Livewire requests — where the policy fails **open**.
- Treat Laravel scopes and Filament tenancy as the only real boundary. Apply `visibleToCompany()` (or the equivalent) explicitly; do not assume the database will scope a query for you.
- `credit_cards` has no RLS at all, by design — it is shared across companies via `sharedCompanies()`.

### Encryption
`account_number`, `description`, `debit`, `credit`, `balance`, and `raw_data` are encrypted at rest. You cannot `WHERE`/`ORDER BY` them in SQL — filter in PHP after decryption.

### Filament gotchas
- Table filter state (`$tableFilters`) is an unlocked public Livewire array with **no revalidation against `options()`** — always re-scope and cast values read from it.
- Duplicate `Action::make('name')` entries both render, but only the last is executable; `assertTableAction*` cannot detect the duplicate.
- `visible()` on an action **is** a server-side guard (`InteractsWithActions` bails on `isDisabled()`), so it does prevent crafted Livewire calls.

---

## Structured Completion Report

When your task is complete (or you've hit a retry limit and stopped), output this report:

```
## Agent Completion Report
- **Status:** Success | Partial | Failed
- **Issue:** #<number> — <title>
- **Branch:** <branch-name>
- **Files modified:** <list>
- **Tests:** <added/modified count> | All passing: Yes/No
- **CI checks:** Pint: Pass/Fail | PHPStan: Pass/Fail | Tests: Pass/Fail
- **Steps completed:** <list of completed TaskCreate items>
- **Steps skipped/failed:** <list with reasons>
- **Gaps or concerns:** <anything the reviewer should know>
```

This format allows the batch orchestrator to parse results and determine next actions.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v5
- laravel/ai (AI) - v0
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- livewire/livewire (LIVEWIRE) - v4
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== ai/core rules ===

## Laravel AI SDK

- This application uses the Laravel AI SDK (`laravel/ai`) for all AI functionality.
- Activate the `developing-with-ai-sdk` skill when building, editing, updating, debugging, or testing AI agents, text generation, chat, streaming, structured output, tools, image generation, audio, transcription, embeddings, reranking, vector stores, files, conversation memory, or any AI provider integration (OpenAI, Anthropic, Gemini, Cohere, Groq, xAI, ElevenLabs, Jina, OpenRouter).

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
