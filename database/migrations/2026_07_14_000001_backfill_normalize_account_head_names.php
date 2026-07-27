<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill: normalize all existing account_heads.name values.
     *
     * The AccountHead Eloquent mutator (added in PR #275) trims and collapses
     * whitespace on every new write, but rows persisted before that change may
     * still contain artifacts like trailing newlines or double spaces. This
     * migration applies the same rule retroactively in a single SQL pass.
     *
     * PostgreSQL equivalent of PHP: (string) preg_replace('/\s+/', ' ', trim($value))
     *   - trim(name)           -> strip leading/trailing whitespace (including \n, \t)
     *   - regexp_replace(...)  -> collapse any internal whitespace run to a single space
     */
    public function up(): void
    {
        DB::statement(
            "UPDATE account_heads
             SET name = trim(regexp_replace(name, '\\s+', ' ', 'g'))
             WHERE name <> trim(regexp_replace(name, '\\s+', ' ', 'g'))"
        );
    }
};
