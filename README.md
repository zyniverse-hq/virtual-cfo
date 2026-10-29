# Virtual CFO

Automated bank statement processing, account head mapping, and Tally XML export — built for the accounts team at Zysk Technologies.

## Pipeline

```
Upload → Parse → Reconcile → Export
```

1. **Upload** — Bank/credit card statements (PDF, CSV, XLSX)
2. **Parse** — LLM-powered extraction of structured transaction data
3. **Reconcile** — Rule-based + AI matching to Tally account heads
4. **Export** — Generate Tally-compatible XML for journal entries

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Backend | PHP 8.4, Laravel 12 |
| Admin Panel | Filament v5 |
| Database | PostgreSQL (JSONB for flexible schemas) |
| AI/LLM | Laravel AI SDK with OpenRouter (Mistral, DeepSeek) |
| Testing | Pest 4, Larastan (PHPStan level 6) |
| PDF Reports | Barryvdh DomPDF |
| Audit Trail | Spatie Activity Log |
| Import/Export | Maatwebsite Excel |
| Queue | Database driver (PostgreSQL-backed) |

## Prerequisites

- PHP 8.4+
- Composer
- PostgreSQL 15+
- Node.js 18+ (for frontend assets)

## Local Setup

```bash
# Clone and install
git clone <repo-url> virtual-cfo
cd virtual-cfo
composer install
npm install && npm run build

# Environment
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your local settings:

```dotenv
DB_DATABASE=virtual_cfo
DB_USERNAME=postgres
DB_PASSWORD=your_password

OPENROUTER_API_KEY=your_key    # Required for AI features
```

```bash
# Database
php artisan migrate --seed

# Serve (or use Laravel Herd)
php artisan serve
```

Create your first admin user:

```bash
php artisan make:filament-user
```

## Key Commands

| Command | Description |
|---------|-------------|
| `php artisan test --compact` | Run full test suite |
| `php artisan test --filter=<name>` | Run specific tests |
| `vendor/bin/pint` | Fix code style (PSR-12) |
| `vendor/bin/phpstan analyse` | Static analysis (level 6) |
| `composer audit` | Check for security vulnerabilities |
| `php artisan migrate:fresh --seed` | Reset database with seed data |
| `php artisan queue:work` | Process background jobs |

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `DB_CONNECTION` | Database driver | `pgsql` |
| `DB_DATABASE` | Database name | `virtual_cfo` |
| `QUEUE_CONNECTION` | Queue backend | `database` |
| `OPENROUTER_API_KEY` | OpenRouter API key for AI features | — |
| `AI_PARSING_MODEL` | Model for statement parsing (must read PDFs natively) | `openai/gpt-6-luna` |
| `AI_MATCHING_MODEL` | Model for head matching | `mistralai/mistral-large-latest` |
| `MAIL_MAILER` | Mail transport | `mailgun` |
| `MAILGUN_DOMAIN` | Mailgun sending domain | — |
| `MAILGUN_SECRET` | Mailgun API key | — |

## Documentation

Detailed documentation lives in the `docs/` directory:

### Architecture
- [Reconciliation Pipeline](docs/architecture/reconciliation-pipeline.md) — Four-stage processing pipeline
- [AI Agent Design](docs/architecture/ai-agent-design.md) — StatementParser, HeadMatcher agents
- [Data Model: JSONB Strategy](docs/architecture/data-model-jsonb.md) — Flexible schema with PostgreSQL JSONB
- [Data Privacy Strategy](docs/architecture/data-privacy-strategy.md) — LLM data handling and pseudonymization
- [Queue Strategy](docs/architecture/queue-strategy.md) — Database queue architecture
- [Connectors Architecture](docs/architecture/connectors.md) — Pluggable invoice sources
- [Tally XML Format](docs/architecture/tally-xml-format.md) — Field reference and examples

### Guides
- [AI-Assisted Development Workflow](docs/guides/ai-assisted-development-workflow.md) — Development process with Claude Code
- [Testing Best Practices](docs/guides/testing-best-practices.md) — Pest conventions and testing diamond
- [Release Process](docs/guides/release-process.md) — Versioning, tagging, deployment

### Project
- [Project Plan](docs/PLAN.md) — Setup plan and implementation roadmap

## License

Proprietary — Zysk Technologies.
