<?php

namespace LiveNet\StatusPage\Filament\Admin\Resources\StatusPages\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use LiveNet\StatusPage\Filament\Admin\Resources\StatusPages\StatusPageResource;
use LiveNet\StatusPage\Models\StatusPage;
use LiveNet\StatusPage\Support\Hostnames;

class ListStatusPages extends ListRecords
{
    protected static string $resource = StatusPageResource::class;

    // Servers whose address comes from the Subdomains plugin have no entry until someone saves one.
    // Create the missing ones (enabled, using the defaults, as the feed already treats them) so they are listed.
    public function mount(): void
    {
        $serverIds = array_keys(Hostnames::all());
        $existing = StatusPage::query()->whereIn('server_id', $serverIds)->pluck('server_id')->all();
        $serverExists = \App\Models\Server::query()->whereIn('id', array_diff($serverIds, $existing))->pluck('id');

        foreach ($serverExists as $id) {
            StatusPage::firstOrCreate(['server_id' => $id], ['enabled' => true]);
        }

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
