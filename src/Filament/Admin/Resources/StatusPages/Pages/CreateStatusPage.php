<?php

namespace LiveNet\StatusPage\Filament\Admin\Resources\StatusPages\Pages;

use App\Enums\TablerIcon;
use App\Traits\Filament\CanCustomizeHeaderActions;
use App\Traits\Filament\CanCustomizeHeaderWidgets;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use LiveNet\StatusPage\Filament\Admin\Resources\StatusPages\StatusPageResource;

class CreateStatusPage extends CreateRecord
{
    use CanCustomizeHeaderActions;
    use CanCustomizeHeaderWidgets;

    protected static string $resource = StatusPageResource::class;

    protected static bool $canCreateAnother = false;

    /** @return array<Action> */
    protected function getDefaultHeaderActions(): array
    {
        return [
            Action::make('create')
                ->hiddenLabel()
                ->action('create')
                ->keyBindings(['mod+s'])
                ->tooltip(trans('filament-panels::resources/pages/create-record.form.actions.create.label'))
                ->icon(TablerIcon::FilePlus),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
