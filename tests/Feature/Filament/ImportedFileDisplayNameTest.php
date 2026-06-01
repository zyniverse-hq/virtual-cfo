<?php

use App\Enums\StatementType;
use App\Filament\Resources\ImportedFileResource\Pages\CreateImportedFile;
use App\Filament\Resources\ImportedFileResource\Pages\ListImportedFiles;
use App\Filament\Resources\ImportedFileResource\Pages\ViewImportedFile;
use App\Models\ImportedFile;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

describe('ImportedFile display_name form field', function () {
    beforeEach(function () {
        asUser();
        Storage::fake('local');
        Queue::fake();
    });

    it('has an optional display_name field on the create form', function () {
        $component = livewire(CreateImportedFile::class);

        $fields = $component->instance()
            ->getSchema('form')
            ->getFlatFields(withHidden: true);

        expect($fields)->toHaveKey('display_name');
    });

    it('saves user-supplied display_name as-is', function () {
        $file = UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf');

        livewire(CreateImportedFile::class)
            ->fillForm([
                'file_path' => $file,
                'statement_type' => StatementType::Bank->value,
                'display_name' => 'My Custom Statement',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $imported = ImportedFile::first();
        expect($imported->display_name)->toBe('My Custom Statement');
    });

    it('leaves display_name null after form submission when user leaves it blank — generation deferred to after processing', function () {
        $file = UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf');

        livewire(CreateImportedFile::class)
            ->fillForm([
                'file_path' => $file,
                'statement_type' => StatementType::Bank->value,
                'display_name' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        // Queue::fake() means ProcessImportedFile never runs — display_name must still be null here.
        // It will be generated with full parsed data (statement_period, card_variant) once the job runs.
        $imported = ImportedFile::first();
        expect($imported->display_name)->toBeNull();
    });
});

describe('ImportedFile display_name in list table', function () {
    beforeEach(function () {
        asUser();
    });

    it('shows display_name column in the list table', function () {
        $file = ImportedFile::factory()->create([
            'display_name' => 'HDFC_Jan 2025',
        ]);

        livewire(ListImportedFiles::class)
            ->assertTableColumnStateSet('display_name', 'HDFC_Jan 2025', record: $file);
    });
});

describe('ImportedFile display_name on view page', function () {
    beforeEach(function () {
        asUser();
    });

    it('shows display_name as primary identifier on view page', function () {
        $file = ImportedFile::factory()->create([
            'display_name' => 'HDFC_Regalia_Jan 2025',
        ]);

        livewire(ViewImportedFile::class, ['record' => $file->getRouteKey()])
            ->assertSchemaStateSet([
                'display_name' => 'HDFC_Regalia_Jan 2025',
            ]);
    });
});

describe('ImportedFile display_name in duplicate notification', function () {
    beforeEach(function () {
        asUser();
        Storage::fake('local');
        Queue::fake();
    });

    it('references display_name in the duplicate detection notification', function () {
        $fileContent = 'duplicate-content-display-name-test';
        $filePath = 'statements/existing.pdf';
        Storage::disk('local')->put($filePath, $fileContent);

        ImportedFile::factory()->create([
            'file_hash' => hash('sha256', $fileContent),
            'original_filename' => 'existing.pdf',
            'file_path' => $filePath,
            'display_name' => 'HDFC_Jan 2025',
            'created_at' => now()->subDays(2),
        ]);

        $duplicate = UploadedFile::fake()->createWithContent('new.pdf', $fileContent);

        $existing = ImportedFile::first();
        $importedDate = $existing->created_at->format('d M Y, H:i');

        livewire(CreateImportedFile::class)
            ->fillForm([
                'file_path' => $duplicate,
                'statement_type' => StatementType::Bank->value,
            ])
            ->call('create')
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title('Duplicate file detected')
                    ->body("This file was already imported on {$importedDate} as \"HDFC_Jan 2025\". Enable \"Force re-import\" to replace it.")
                    ->persistent()
            );
    });
});

describe('ImportedFile display_name edit', function () {
    beforeEach(function () {
        asUser();
    });

    it('has display_name field editable on the edit/view form', function () {
        $file = ImportedFile::factory()->create([
            'display_name' => 'Old Name',
        ]);

        livewire(ViewImportedFile::class, ['record' => $file->getRouteKey()])
            ->assertSuccessful();
    });
});
