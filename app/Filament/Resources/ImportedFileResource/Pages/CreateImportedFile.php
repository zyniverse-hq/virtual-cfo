<?php

namespace App\Filament\Resources\ImportedFileResource\Pages;

use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Filament\Resources\ImportedFileResource;
use App\Jobs\ProcessImportedFile;
use App\Models\Company;
use App\Models\ImportedFile;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CreateImportedFile extends CreateRecord
{
    protected static string $resource = ImportedFileResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['uploaded_by'] = Auth::id();
        $data['status'] = ImportStatus::Pending;
        $data['source'] = ImportSource::ManualUpload;

        // Generate file hash for duplicate detection
        $filePath = $data['file_path'];
        $contents = Storage::disk('local')->get($filePath);
        if ($contents !== null) {
            $data['file_hash'] = hash('sha256', $contents);
        }

        // Store original filename
        $data['original_filename'] = basename($filePath);

        // Check for duplicate file
        if (isset($data['file_hash'])) {
            /** @var Company $tenant */
            $tenant = Filament::getTenant();

            $existing = ImportedFile::where('company_id', $tenant->id)
                ->where('file_hash', $data['file_hash'])
                ->first();

            if ($existing) {
                $forceReimport = $data['force_reimport'] ?? false;

                if ($forceReimport) {
                    $existing->transactions()->delete();
                    $existing->delete();
                } else {
                    $importedDate = $existing->created_at->format('d M Y, H:i');

                    Notification::make()
                        ->danger()
                        ->title('Duplicate file detected')
                        ->body("This file was already imported on {$importedDate} as \"{$existing->display_name}\". Enable \"Force re-import\" to replace it.")
                        ->persistent()
                        ->send();

                    throw (new Halt)->rollBackDatabaseTransaction();
                }
            }
        }

        // Store pdf_password in source_metadata if provided
        if (! empty($data['pdf_password'])) {
            $data['source_metadata'] = array_merge(
                $data['source_metadata'] ?? [],
                ['manual_password' => $data['pdf_password']],
            );
        }

        // Remove non-database fields
        unset($data['force_reimport'], $data['pdf_password']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var ImportedFile $record */
        $record = $this->record;

        ProcessImportedFile::dispatch($record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
