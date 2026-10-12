<?php

namespace ChristianHnl\PowerMetrics\Filament\Admin\Widgets;

use ChristianHnl\PowerMetrics\Services\PowerStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PowerOverviewWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    /** @return Stat[] */
    protected function getStats(): array
    {
        $stats = app(PowerStats::class);

        $now = $stats->now();
        $monthStart = $now->copy()->startOfMonth();
        $dayStart = $now->copy()->startOfDay();

        $current = $stats->current();
        $today = $stats->summary($dayStart, $dayStart->copy()->addDay());
        $month = $stats->summary($monthStart, $monthStart->copy()->addMonth());
        $lastMonth = $stats->summary($monthStart->copy()->subMonth(), $monthStart);
        $forecast = $stats->monthForecastKwh();

        $sparkline = $stats->series('1h')['avg'];
        $dateFormat = 'd.m. H:i';

        return [
            Stat::make(trans('power-metrics::strings.stats.current'), PowerStats::watts($current['watts']))
                ->icon('tabler-bolt')
                ->description(match (true) {
                    $current['watts'] === null => trans('power-metrics::strings.stats.no_data'),
                    $current['estimated'] === 0 => trans('power-metrics::strings.stats.measured'),
                    $current['estimated'] === $current['online'] => trans('power-metrics::strings.stats.estimated'),
                    default => trans('power-metrics::strings.stats.partly_estimated', ['count' => $current['estimated'], 'total' => $current['online']]),
                })
                ->descriptionIcon($current['watts'] !== null && $current['estimated'] > 0 ? 'tabler-calculator' : null)
                ->color(match (true) {
                    $current['watts'] === null => 'gray',
                    $current['estimated'] > 0 => 'warning',
                    default => 'success',
                })
                ->chart($sparkline ?: null),

            Stat::make(trans('power-metrics::strings.stats.today'), PowerStats::kwh($today['kwh']))
                ->icon('tabler-sun')
                ->description(trans('power-metrics::strings.stats.cost_today', ['cost' => PowerStats::cost($today['kwh'])]))
                ->color('info'),

            Stat::make(trans('power-metrics::strings.stats.month'), PowerStats::kwh($month['kwh']))
                ->icon('tabler-calendar-month')
                ->description(trans('power-metrics::strings.stats.last_month', ['value' => PowerStats::kwh($lastMonth['kwh'])])
                    . ($month['estimated_share'] > 0 ? ' · ' . trans('power-metrics::strings.stats.estimated_share', ['percent' => round($month['estimated_share'] * 100)]) : ''))
                ->color('info'),

            Stat::make(trans('power-metrics::strings.stats.cost_month'), PowerStats::cost($month['kwh']))
                ->icon('tabler-coin')
                ->description(number_format((float) config('power-metrics.price_per_kwh'), 4, app()->getLocale() === 'de' ? ',' : '.', '') . ' ' . config('power-metrics.currency') . ' / kWh')
                ->color('warning'),

            Stat::make(trans('power-metrics::strings.stats.forecast'), $forecast === null ? '–' : PowerStats::kwh($forecast))
                ->icon('tabler-chart-line')
                ->description($forecast === null
                    ? trans('power-metrics::strings.stats.no_data')
                    : PowerStats::cost($forecast) . ' · ' . trans('power-metrics::strings.stats.forecast_hint', ['days' => $monthStart->daysInMonth]))
                ->color('gray'),

            Stat::make(trans('power-metrics::strings.stats.average'), PowerStats::watts($month['avg']))
                ->icon('tabler-chart-arrows-vertical')
                ->description(trans('power-metrics::strings.stats.average_today', ['value' => PowerStats::watts($today['avg'])]))
                ->color('primary'),

            Stat::make(trans('power-metrics::strings.stats.peak'), PowerStats::watts($month['peak']))
                ->icon('tabler-arrow-up')
                ->description($month['peak_at'] ? trans('power-metrics::strings.stats.at', ['date' => $month['peak_at']->format($dateFormat)]) : null)
                ->color('danger'),

            Stat::make(trans('power-metrics::strings.stats.low'), PowerStats::watts($month['low']))
                ->icon('tabler-arrow-down')
                ->description($month['low_at'] ? trans('power-metrics::strings.stats.at', ['date' => $month['low_at']->format($dateFormat)]) : null)
                ->color('success'),

            Stat::make(trans('power-metrics::strings.stats.last_month_total'), PowerStats::cost($lastMonth['kwh']))
                ->icon('tabler-history')
                ->description(PowerStats::kwh($lastMonth['kwh']))
                ->color('gray'),
        ];
    }
}
