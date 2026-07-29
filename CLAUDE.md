# Virtual CFO - Zysk Technologies

## Tone

Do not tell me I am right all the time. Be critical. We're equals. Try to be neutral and objective.

Never mention Claude Code in PR descriptions, PR comments, or issue comments.

## Commands

```bash
php artisan test --compact                    # Full test suite
php artisan test --filter=<name>              # Specific tests
vendor/bin/phpstan analyse                    # Static analysis (level 6)
composer audit                                # Security vulnerability check
php artisan migrate:fresh --seed              # Reset database
```

## MCP Tools (Laravel Boost)

- `search-docs` - Search Laravel/Pest docs for version-specific patterns (Laravel 12, Pest 4)
- `database-schema` - Review existing tables before creating migrations
- `database-query` - Run queries to verify data/relationships
- `tinker` - Test code snippets and verify implementations
- `list-routes` - Check existing routes
- `last-error` - Check last Laravel error when debugging failures
- `browser-logs` - Read browser console errors when debugging Filament UI

Use `search-docs` before implementing non-trivial Laravel features.

### On-Demand Boost Guidelines (activate when relevant)

| Guideline | Activate With | When to Load |
|-----------|--------------|-------------|
| **Filament v5** | `/mcp__laravel-boost__filament/filament` | Building resources, forms, tables, actions, testing CRUD |
| **Laravel AI SDK** | `/mcp__laravel-boost__laravel/ai` | Working on agents, structured output, tools, providers |
| **Code Simplifier** | `/simplify` (laravel-simplifier plugin) | TDD refactor step — after tests pass, before commit |

Pest, Livewire, Pint, and core Laravel guidelines are always-on (embedded via Boost).

## Workflow

**Cycle:** Explore → Plan → Code → Commit

1. **Explore** - Read relevant files, understand context. Don't write code yet.
2. **Plan** - Use Plan Mode for non-trivial work. Create issue via `gh issue create`.
3. **Code** - TDD: Write failing tests → Implement → Refactor. Be explicit about TDD to avoid mock implementations.
4. **Simplify** - Run `/simplify` on modified files during the REFACTOR step.
5. **Verify** - Tests are the verification. Run `php artisan test --filter=<related>` and ensure all pass before committing.
5. **Commit** - At natural breakpoints: after tests written, after tests pass, after PR ready.

**Branch:** `<type>/<issue#>-<description>` (e.g., `feat/42-import-parser`)
**Commit:** `<type>(scope): description (#issue)`
**Types:** `feat`, `fix`, `refactor`, `test`, `docs`, `chore`
**PR:** `gh pr create` — must include "Closes #XX"

**TDD (IMPORTANT):** RED → GREEN → REFACTOR. Don't skip the refactor step. Run `/simplify` (laravel-simplifier plugin) during REFACTOR to enforce Laravel conventions, PSR-12 standards, and project patterns on modified code. Re-run tests after simplification.

**Context:** Use `/clear` between unrelated tasks to avoid context pollution.

## Branching Strategy (Trunk-Based)

```
feature/* → master (squash merge) → CD → staging/QA → tag vX.Y.Z → production
```

| Branch | Purpose | PR Target | Protection |
|--------|---------|-----------|------------|
| `master` | Trunk — staging/QA deploys from here | - | All CI + 1 review + admins |
| `feature/*`, `fix/*` | New work | `master` | None |
| `hotfix/*` | Urgent prod fixes | `master` | None |

**Merge strategy:** Always squash merge into `master`.
**CI checks required before merge:** Syntax, Pint, PHPStan, Security, Tests
**PR labels:** Every PR must have a `type:` label (`type: feature`, `type: bug`, `type: refactor`, `type: docs`, `type: chore`) for release notes categorization.

## Hard Gates (NEVER skip)

- **Laravel Boost tools**: At the START of every task, run `ToolSearch` for Laravel Boost MCP tools (`database-schema`, `search-docs`, `tinker`, etc.). If unavailable, tell the user immediately — do not silently proceed without them.
- **`/simplify`**: Run on EVERY task before final commit, regardless of task type (feature, fix, config, chore, refactor). Not just TDD REFACTOR — every task.
- **CI verification**: Run `bash bin/ci-check.sh` before every commit. Do not commit with failing checks.
- **Progress tracking**: Use `TaskCreate`/`TaskUpdate` to track workflow steps. Verify all steps are completed before commit.

### Releases

Semantic versioning (`vX.Y.Z`). Releases are cut by tagging `master`:

```bash
gh release create vX.Y.Z --generate-notes --target master --title "vX.Y.Z"
```

See [Release Process](docs/guides/release-process.md) for full details (pre-release checklist, deployment, rollback).

## Common Mistakes (AVOID)

These patterns have caused issues — don't repeat them:

- **Don't skip REFACTOR in TDD** - After tests pass, activate Laravel Code Simplifier to clean up modified code, then re-run tests
- **Don't use inline validation** - Always use Form Request classes, never `$request->validate()`
- **Don't create mock implementations** - During TDD, write real code that passes tests
- **Don't use complex bash commands** - Pipes/loops stall on Windows; use built-in tools instead
- **Don't assume FK indexes exist** - PostgreSQL doesn't auto-create them; add explicitly
- **Don't forget to read docs first** - Check `docs/` before implementing

## Project Overview

Virtual CFO application for automating bank/credit card statement processing, account head mapping, and Tally XML export. Built for the accounts team at Zysk Technologies.

## Tech Stack

- PHP 8.4 + Laravel 12
- Filament v5 (admin panel)
- PostgreSQL (single database, JSONB for flexible schemas)
- Laravel AI SDK (`laravel/ai`) with Mistral as primary LLM provider
- Laravel Boost + Filament Blueprint
- Pest 4 (testing) + Larastan (static analysis)
- Database queue driver (`php artisan queue:work`)
- Maatwebsite Excel (CSV/Excel import/export)
- Spatie Activity Log (audit trail)
- Barryvdh DomPDF (PDF report generation)

## Architecture Decisions

### Database: PostgreSQL (not MongoDB)
Originally considered MongoDB for schema flexibility (different banks have different column names). Chose PostgreSQL with JSONB columns instead because:
- Filament does not officially support MongoDB — causes "database engine does not support inserting while ignoring errors"
- JSONB provides identical schema flexibility with `raw_data` column
- Single database eliminates hybrid complexity
- Full ACID transactions, GIN indexes on JSONB, relational integrity

### LLM: Laravel AI SDK (not Prism PHP)
Chose `laravel/ai` over `prism-php/prism` because:
- First-party Laravel package — guaranteed long-term support
- Native agent framework (`php artisan make:agent`)
- Built-in file attachments, queue support, structured output
- Built-in testing with `Agent::fake()` and assertions
- Prism PHP is community-maintained with less integrated features

### PDF Parsing: LLM-powered (not regex/pdfparser)
Using Mistral LLM to parse bank statements instead of smalot/pdfparser because:
- Bank statements have wildly different layouts per bank
- Regex-based parsing requires per-bank parser maintenance
- LLM handles any format — detects columns, extracts structured data
- Works for both text-based and scanned PDFs
- Cost: ~$2/1000 pages via Mistral

### Head Matching: Hybrid (rules + LLM)
Two-pass approach:
1. Rule-based matching (fast, cheap, deterministic) for known patterns
2. LLM-based matching for ambiguous transactions with confidence scores
3. Manual review for anything below confidence threshold

### Encryption
All sensitive financial data encrypted at rest using Laravel's built-in encryption (AES-256-CBC via APP_KEY). Encrypted fields: account_number, description, debit, credit, balance, raw_data.

### Data Privacy: Hybrid LLM Strategy
HeadMatcher agent uses pseudonymization (mask party names, account numbers before sending to LLM). OCR and parsing agents require unmasked data — protected by provider DPAs and zero-retention APIs. All LLM calls are audit-logged (metadata only, never content). See [Data Privacy Strategy](docs/architecture/data-privacy-strategy.md).

### Queue: Database driver (not Redis + Horizon)
Removed `laravel/horizon` and Redis dependency. Using PostgreSQL-backed database queue instead because:
- Very few users (small accounts team) — Redis throughput is unnecessary
- One less infrastructure dependency to install, configure, and monitor
- The bottleneck is external AI API calls (5-30s), not queue dispatch speed
- Database queues provide ACID guarantees on job storage
- Jobs are inspectable via SQL — easier debugging
- `php artisan queue:work` is sufficient; Horizon's dashboard adds no value at this scale

### File Storage
PDFs stored in `storage/app/private/statements/` — never publicly accessible. Served only through authenticated Filament routes.

## Environment

- PostgreSQL required (not MySQL/SQLite)
- Pint auto-runs via hook after PHP edits — no need to run manually
- Tests use Pest with `describe`/`it` blocks — follow existing test patterns

## Model Safety Mechanisms

Laravel's safety mechanisms are enabled in non-production environments:

| Mechanism | Purpose | Fix |
|-----------|---------|-----|
| `preventLazyLoading` | Catches N+1 queries | Use `with()` for eager loading |
| `preventAccessingMissingAttributes` | Catches typos in attributes | Add missing accessors |
| `preventSilentlyDiscardingAttributes` | Catches mass assignment issues | Add to `$fillable` |

**Windows CLI (IMPORTANT):** Complex bash commands (pipes, loops) may stall. Use built-in tools instead:
- `Grep` tool instead of `grep` or `rg` commands
- `Glob` tool instead of `find` commands
- `Read` tool instead of `cat`, `head`, `tail` commands

## PostgreSQL Rules (IMPORTANT)

For new migrations, these rules are non-negotiable:

| Do | Don't |
|----|-------|
| `TEXT` | `VARCHAR(n)` |
| `TIMESTAMPTZ` | `TIMESTAMP` |
| `BIGINT GENERATED ALWAYS AS IDENTITY` | `SERIAL` |
| `JSONB` with CHECK constraint | `JSON` |
| Explicit FK indexes | Assume auto-indexing |
| Partial unique: `WHERE deleted_at IS NULL` | Table-level unique with soft deletes |

## Key Patterns

### Enums
- `ImportStatus`: pending, processing, completed, failed
- `MappingType`: unmapped, auto, manual, ai
- `MatchType`: contains, exact, regex
- `StatementType`: bank, credit_card, invoice (planned)

### AI Agents
- `StatementParser` — PDF bank/CC statements → structured transaction data
- `InvoiceParser` — PDF invoices → vendor details, GST breakup, line items (planned, see #42)
- `HeadMatcher` — transaction descriptions → account head suggestions with confidence

See [AI Agent Design](docs/architecture/ai-agent-design.md) for architecture rationale.

**Model configuration:** Each agent reads its model from `config/ai.php` via environment variables:

| Agent | Env Var | Config Key | Default |
|-------|---------|------------|---------|
| `StatementParser` | `AI_PARSING_MODEL` | `ai.models.parsing` | `mistral-large-latest` |
| `HeadMatcher` | `AI_MATCHING_MODEL` | `ai.models.matching` | `mistral-large-latest` |

The agents use the `model()` method (laravel/ai convention) to resolve the model at runtime, allowing model changes without code modifications.

### Background Jobs
- `ProcessImportedFile` — parses PDF via StatementParser agent, creates transactions
- `MatchTransactionHeads` — runs rule-based + AI matching on unmapped transactions

## Documentation

| Folder | Purpose | When to Read |
|--------|---------|--------------|
| `docs/architecture/` | Architecture decisions (pipeline, agents, data model, Tally XML, data privacy) | Before implementing #40–#43, #15, #54–#55 |
| `docs/guides/` | How-to guides (AI workflow, testing, releases) | For step-by-step workflows |
| `docs/PLAN.md` | Project setup plan and implementation order | For project context |

### AI-Assisted Development Workflow

Follow the [AI-Assisted Development Workflow](docs/guides/ai-assisted-development-workflow.md) for all implementation tasks. It defines the 7-step process (Understand → Implement → CI Checks → Review & Test → Commit → Comment → Next Task) including TDD workflows, MCP tool usage, and quality gates.

### Testing Best Practices

Follow the [Testing Best Practices](docs/guides/testing-best-practices.md) guide when writing or reviewing tests. Key rules:

- **Testing Diamond:** ~5% static, ~25% unit/arch, ~65% integration, ~5% E2E
- **No config-value assertions** — test behavior, not `config('key') === 'value'`
- **No Reflection-based tests** — test public API, not internal implementation
- **No assertOk-only tests** — every test must assert something beyond HTTP 200
- **Filament CRUD tests** must verify form fields, table records, or visible content

## Pipeline Architecture

```
Upload → Parse → Reconcile → Export
```

The full pipeline is documented in `docs/architecture/`:
- [Reconciliation Pipeline](docs/architecture/reconciliation-pipeline.md) — overview and dependency graph
- [AI Agent Design](docs/architecture/ai-agent-design.md) — why focused agents behind one service
- [Data Model: JSONB Strategy](docs/architecture/data-model-jsonb.md) — raw_data flow through stages
- [Data Privacy Strategy](docs/architecture/data-privacy-strategy.md) — LLM data exposure, pseudonymization, provider DPAs
- [Connectors Architecture](docs/architecture/connectors.md) — pluggable invoice sources (email, Zoho, API)
- [Tally XML Format](docs/architecture/tally-xml-format.md) — field reference and examples from real Tally export

## Development Notes
- Very few users (small accounts team)
- Tally XML reference file: `DayBook zysk april25.xml` (April 2025, UTF-16LE, 383 vouchers)
- OCR support via Mistral handles scanned PDFs automatically

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
