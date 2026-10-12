<?php

namespace ChristianHnl\PowerMetrics\Providers;

use App\Models\Role;
use Illuminate\Console\Scheduling\Schedule;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerDailyChart;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerHistoryChart;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerOverviewWidget;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerProfileChart;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class PowerMetricsPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        Role::registerCustomDefaultPermissions('power_source');
        Role::registerCustomModelIcon('power_source', 'tabler-bolt');
    }

    public function boot(): void
    {
        // Die Widgets gehören nur zur Seite (nicht zum Dashboard) und werden daher hier selbst registriert
        foreach ([PowerOverviewWidget::class, PowerHistoryChart::class, PowerDailyChart::class, PowerProfileChart::class] as $widget) {
            Livewire::component($widget);
        }

        $this->app->booted(function () {
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('power-metrics:collect')->everyMinute()->withoutOverlapping(5);
            $schedule->command('power-metrics:prune')->dailyAt('03:17');
        });
    }
}
