<?php

namespace LiveNet\StatusPage\Filament\Admin\Resources\StatusPages\Pages;

use App\Enums\TablerIcon;
use App\Traits\Filament\CanCustomizeHeaderActions;
use App\Traits\Filament\CanCustomizeHeaderWidgets;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use LiveNet\StatusPage\Filament\Admin\Resources\StatusPages\StatusPageResource;

class EditStatusPage extends EditRecord
{
    use CanCustomizeHeaderActions;
    use CanCustomizeHeaderWidgets;

    protected static string $resource = StatusPageResource::class;

    /** @return array<Action> */
    protected function getDefaultHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            Action::make('save')
                ->hiddenLabel()
                ->action('save')
                ->keyBindings(['mod+s'])
                ->tooltip(trans('filament-panels::resources/pages/edit-record.form.actions.save.label'))
                ->icon(TablerIcon::DeviceFloppy),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
