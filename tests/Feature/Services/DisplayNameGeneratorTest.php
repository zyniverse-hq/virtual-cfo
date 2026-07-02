<?php

use App\Enums\StatementType;
use App\Models\ImportedFile;
use App\Services\DisplayNameGenerator;

it('generates a clean display name with all metadata present', function () {
    $file = new ImportedFile;
    $file->forceFill([
        'bank_name' => 'HDFC Bank',
        'card_variant' => 'Regalia',
        'statement_period' => '01 Jan 2025 to 31 Jan 2025',
        'statement_type' => StatementType::Bank,
        'created_at' => now(),
    ]);

    $generator = new DisplayNameGenerator;
    $displayName = $generator->generate($file);

    expect($displayName)->toBe('HDFC Bank Regalia Jan 2025');
});

it('generates a clean display name and handles missing bank names without leading spaces', function () {
    $file = new ImportedFile;
    $file->forceFill([
        'bank_name' => null, // Simulating missing bank name
        'card_variant' => null,
        'statement_period' => '01 Jul 2026 to 31 Jul 2026',
        'statement_type' => StatementType::Bank,
        'created_at' => now(),
    ]);

    $generator = new DisplayNameGenerator;
    $displayName = $generator->generate($file);

    // It should just be "Jul 2026", not "_Jul_2026" or " Jul 2026"
    expect($displayName)->toBe('Jul 2026');
});

it('handles missing card variant correctly', function () {
    $file = new ImportedFile;
    $file->forceFill([
        'bank_name' => 'SBI',
        'card_variant' => null,
        'statement_period' => '01 Aug 2025 to 31 Aug 2025',
        'statement_type' => StatementType::Bank,
        'created_at' => now(),
    ]);

    $generator = new DisplayNameGenerator;
    $displayName = $generator->generate($file);

    expect($displayName)->toBe('SBI Aug 2025');
});
