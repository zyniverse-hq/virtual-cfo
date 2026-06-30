<?php

namespace App\Filament\Resources\AccountHeadResource\Pages;

use App\Filament\Resources\AccountHeadResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAccountHead extends EditRecord
{
    protected static string $resource = AccountHeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (\App\Models\AccountHead $record, Actions\DeleteAction $action) {
                    AccountHeadResource::validateDeletion($record, $action);
                }),
            Actions\ForceDeleteAction::make()
                ->before(function (\App\Models\AccountHead $record, Actions\ForceDeleteAction $action) {
                    AccountHeadResource::validateDeletion($record, $action);
                }),
            Actions\RestoreAction::make(),
        ];
    }
}
