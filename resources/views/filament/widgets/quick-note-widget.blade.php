<x-filament-widgets::widget>
    <x-filament::section heading="Quick Notes">
        <form wire:submit="save">
            {{ $this->form }}

            <div class="mt-4">
                <x-filament::button type="submit">
                    Save Notes
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>
</x-filament-widgets::widget>
