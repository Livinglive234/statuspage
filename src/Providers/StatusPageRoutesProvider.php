<?php

namespace LiveNet\StatusPage\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;
use LiveNet\StatusPage\Http\Controllers\FeedController;

class StatusPageRoutesProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        $this->routes(function () {
            Route::middleware(['throttle:60,1'])
                ->get('/api/statuspage/feed', FeedController::class)
                ->name('statuspage.feed');
        });
    }
}
