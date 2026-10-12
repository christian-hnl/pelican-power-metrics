<?php

namespace ChristianHnl\PowerMetrics\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

class PowerMetricsRoutesProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        $this->routes(function () {
            Route::middleware(['api', 'throttle:120,1'])
                ->prefix('/api/power-metrics')
                ->group(plugin_path('power-metrics', 'routes/api.php'));
        });
    }
}
