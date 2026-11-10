<?php

namespace App\Filament\Resources\Sponsor\Pages;

use App\Filament\Resources\Sponsor\SponsorOrganizationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSponsorOrganization extends EditRecord
{
    protected static string $resource = SponsorOrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SponsorOrganizationResource::recordTransferAction(),
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
