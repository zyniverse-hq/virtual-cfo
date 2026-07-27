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

These hooks run automatically on every file edit — you do NOT need to run them manually:

| Hook | Trigger | What it does |
|------|---------|-------------|
| `format-php.sh` | After Edit/Write of `.php` files | Auto-runs Pint formatting |
| `phpstan-check.sh` | After Edit/Write of non-test `.php` files | Runs PHPStan level 6 |
| `protect-files.sh` | Before Edit/Write | Blocks changes to `.env`, `composer.lock`, `phpunit.xml`, `docs/schema/*.sql` |
| `block-dangerous-commands.sh` | Before Bash | Blocks `rm -rf`, `git reset --hard`, force push to main |

**Do not panic** if you see auto-formatting changes after editing PHP. That is the Pint hook. Do not undo these changes.

The CI check in Step 8 (`bash bin/ci-check.sh`) is **project-wide verification** — it runs the full suite, not per-file. This is separate from hooks and still required before commit.

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

# 4. Set ALL database credentials in .env (3 connections required):
#    DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD         (healthcheck)
#    SECOND_DB_HOST, SECOND_DB_DATABASE, SECOND_DB_USERNAME, SECOND_DB_PASSWORD  (central)
#    THIRD_DB_HOST, THIRD_DB_DATABASE, THIRD_DB_USERNAME, THIRD_DB_PASSWORD      (infirmary)

# 5. Clear config cache
php artisan config:clear

# 6. Do NOT run `git checkout main` — the worktree is already based on main
```

**Test databases** (defined in `phpunit.xml`): `virtual_cfo_test`. If tests fail with "unknown database", these need to be created in PostgreSQL first — this is an environment error, not a code error.

---

## Key Coding Rules

These are the most critical rules from `.claude/rules/`. The full rule files auto-load based on file path when you edit files — consult them for complete details.

### Validation
- **Always** use Form Request classes — never `$request->validate()` inline
- Authorize via Form Request `authorize()` method or Policy

### Tests
- Use Pest `describe`/`it` blocks — never `test()`
- Use factories for database records — never mock models
- Every mutation test needs 3 assertions: status + response body + side effect
- Never write `assertOk()`-only tests

### Response Status Codes
- 200: GET/PUT/PATCH success
- 201: POST creation success
- 204: DELETE success (no body)
- 422: Validation errors only
- 403: Unauthorized (never "Forbidden.")
- 409: Business rule violations

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
