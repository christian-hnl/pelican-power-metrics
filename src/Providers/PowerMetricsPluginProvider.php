<?php

namespace ChristianHnl\PowerMetrics\Providers;

use App\Models\Role;
use Illuminate\Console\Scheduling\Schedule;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerDailyChart;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerHistoryChart;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerOverviewWidget;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerProfileChart;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerStatusWidget;
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
        foreach ([PowerStatusWidget::class, PowerOverviewWidget::class, PowerHistoryChart::class, PowerDailyChart::class, PowerProfileChart::class] as $widget) {
            Livewire::component($widget);
        }

        // Von Laravel für Pakete empfohlen: greift, sobald (bzw. falls bereits) der Scheduler aufgelöst wird
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('power-metrics:collect')->everyMinute()->withoutOverlapping(5);
            $schedule->command('power-metrics:prune')->dailyAt('03:17');
        });
    }
}
