<?php

namespace ChristianHnl\PowerMetrics\Filament\Admin\Pages;

use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerDailyChart;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerHistoryChart;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerOverviewWidget;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerProfileChart;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerStatusWidget;
use ChristianHnl\PowerMetrics\Models\PowerSource;
use App\Filament\Admin\Resources\Plugins\PluginResource;
use Filament\Actions\Action;
use Filament\Pages\Page;

class PowerUsagePage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-bolt';

    protected static ?string $slug = 'power-usage';

    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        return user()?->can('viewAny', PowerSource::class) ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return trans('power-metrics::strings.navigation');
    }

    public function getTitle(): string
    {
        return trans('power-metrics::strings.title');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setup')
                ->label(trans('power-metrics::strings.settings.setup'))
                ->icon('tabler-settings')
                ->url(fn () => PluginResource::getUrl())
                ->visible(fn () => PluginResource::canAccess()),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PowerStatusWidget::class,
            PowerOverviewWidget::class,
            PowerHistoryChart::class,
            PowerDailyChart::class,
            PowerProfileChart::class,
        ];
    }

    /** Eine Spalte auf Handy/Tablet, zwei auf großen Bildschirmen. */
    public function getHeaderWidgetsColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
