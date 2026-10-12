<?php

namespace ChristianHnl\PowerMetrics\Filament\Admin\Pages;

use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerDailyChart;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerHistoryChart;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerOverviewWidget;
use ChristianHnl\PowerMetrics\Filament\Admin\Widgets\PowerProfileChart;
use ChristianHnl\PowerMetrics\Models\PowerSource;
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

    protected function getHeaderWidgets(): array
    {
        return [
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
