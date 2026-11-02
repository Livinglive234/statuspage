<?php

namespace LiveNet\StatusPage\Providers;

use Illuminate\Support\ServiceProvider;
use LiveNet\StatusPage\Models\StatusPage;

class StatusPageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(plugin_path('statuspage', 'config/statuspage.php'), 'statuspage');
    }

    public function boot(): void
    {
        // The table has no foreign key (column types differ between panel versions),
        // so clean up entries ourselves when a server is deleted.
        if (class_exists(\App\Models\Server::class)) {
            \App\Models\Server::deleted(function ($server) {
                StatusPage::query()->where('server_id', $server->id)->delete();
            });
        }
    }
}
