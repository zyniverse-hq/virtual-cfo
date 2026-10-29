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
            AccountHeadResource::customizeDeleteAction(Actions\DeleteAction::make()),
            AccountHeadResource::customizeDeleteAction(Actions\ForceDeleteAction::make(), true),
            Actions\RestoreAction::make(),
        ];
    }
}
