<?php

use App\Models\ImportedFile;

describe('imports:backfill-display-names', function () {
    it('populates display_name for records that have none', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => 'HDFC',
            'statement_period' => 'Jan 2025',
            'credit_card_id' => null,
            'display_name' => null,
        ]);

        $this->artisan('imports:backfill-display-names')
            ->assertSuccessful();

        $file->refresh();
        expect($file->display_name)->not->toBeNull()
            ->and($file->display_name)->toBe('HDFC Jan 2025');
    });

    it('does not overwrite existing display_name values', function () {
        $file = ImportedFile::factory()->create([
            'bank_name' => 'HDFC',
            'statement_period' => 'Jan 2025',
            'display_name' => 'Already Set',
        ]);

        $this->artisan('imports:backfill-display-names')
            ->assertSuccessful();

        $file->refresh();
        expect($file->display_name)->toBe('Already Set');
    });

    it('reports the number of records updated', function () {
        ImportedFile::factory()->count(3)->create([
            'bank_name' => 'SBI',
            'statement_period' => 'Feb 2025',
            'display_name' => null,
        ]);

        $this->artisan('imports:backfill-display-names')
            ->expectsOutputToContain('3')
            ->assertSuccessful();
    });
});
