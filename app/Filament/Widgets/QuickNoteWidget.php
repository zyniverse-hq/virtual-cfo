<?php

namespace App\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;

/**
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
        $tenant = \Filament\Facades\Filament::getTenant();
        $quickNotes = null;
        
        if ($tenant instanceof \App\Models\Company) {
            $company = auth()->user()?->companies()->where('company_id', $tenant->id)->first();
            // @phpstan-ignore property.notFound
            $quickNotes = $company?->pivot?->quick_notes;
        }

        $this->form->fill([
            'notes' => $quickNotes,
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
        $tenant = \Filament\Facades\Filament::getTenant();

        if ($user && $tenant instanceof \App\Models\Company) {
            $user->companies()->updateExistingPivot($tenant->id, [
                'quick_notes' => $data['notes'] ?? null,
            ]);

            Notification::make()
                ->title('Notes saved successfully')
                ->success()
                ->send();
        }
    }
}
