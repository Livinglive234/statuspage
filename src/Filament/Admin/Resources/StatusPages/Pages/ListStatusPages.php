<?php

namespace LiveNet\StatusPage\Filament\Admin\Resources\StatusPages\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use LiveNet\StatusPage\Filament\Admin\Resources\StatusPages\StatusPageResource;

class ListStatusPages extends ListRecords
{
    protected static string $resource = StatusPageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
