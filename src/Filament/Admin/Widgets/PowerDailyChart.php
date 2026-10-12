<?php

namespace ChristianHnl\PowerMetrics\Filament\Admin\Widgets;

use ChristianHnl\PowerMetrics\Services\PowerStats;
use Filament\Widgets\ChartWidget;

class PowerDailyChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    protected ?string $maxHeight = '320px';

    public ?string $filter = 'month';

    public function getHeading(): string
    {
        return trans('power-metrics::strings.charts.daily');
    }

    protected function getFilters(): ?array
    {
        return [
            'month' => trans('power-metrics::strings.ranges.month'),
            '30d' => trans('power-metrics::strings.ranges.30d'),
            '90d' => trans('power-metrics::strings.ranges.90d'),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $stats = app(PowerStats::class);
        $today = $stats->now()->startOfDay();

        [$from, $to] = match ($this->filter) {
            '30d' => [$today->copy()->subDays(29), $today->copy()->addDay()],
            '90d' => [$today->copy()->subDays(89), $today->copy()->addDay()],
            default => [$today->copy()->startOfMonth(), $today->copy()->startOfMonth()->addMonth()],
        };

        $daily = $stats->dailyEnergy($from, $to);

        return [
            'labels' => $daily['labels'],
            'datasets' => [
                [
                    'label' => trans('power-metrics::strings.charts.kwh'),
                    'data' => $daily['kwh'],
                    'backgroundColor' => '#3b82f6',
                    'borderRadius' => 4,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['display' => false]],
            'scales' => [
                'x' => ['ticks' => ['maxTicksLimit' => 10, 'maxRotation' => 0, 'autoSkip' => true]],
                'y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => 'kWh']],
            ],
        ];
    }
}
