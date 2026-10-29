<?php

namespace App\Filament\Widgets;

use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Crypt;

/**
 * Per-user, per-company scratchpad stored encrypted on the company_user pivot.
 *
 * @property Schema $form
 */
class QuickNoteWidget extends Widget implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.widgets.quick-note-widget';

    protected int|string|array $columnSpan = 'full';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'notes' => $this->loadNotes(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Textarea::make('notes')
                    ->hiddenLabel()
                    ->placeholder('Jot down reminders and quick notes here...')
                    ->rows(5)
                    ->maxLength(65535)
                    ->autosize(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $user = auth()->user();
        $tenant = Filament::getTenant();
        $notes = $data['notes'] ?? null;

        $updated = $user instanceof User && $tenant instanceof Company
            ? $user->companies()->updateExistingPivot($tenant->id, [
                'quick_notes' => filled($notes) ? Crypt::encryptString($notes) : null,
            ])
            : 0;

        if ($updated === 0) {
            Notification::make()
                ->title('Notes could not be saved')
                ->body('You must be a member of the selected company to save notes.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Notes saved successfully')
            ->success()
            ->send();
    }

    private function loadNotes(): ?string
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();

        if (! $user instanceof User || ! $tenant instanceof Company) {
            return null;
        }

        $stored = $user->companies()
            ->where('companies.id', $tenant->id)
            ->value('company_user.quick_notes');

        return is_string($stored) && $stored !== '' ? Crypt::decryptString($stored) : null;
    }
}
