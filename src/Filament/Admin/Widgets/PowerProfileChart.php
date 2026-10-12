<?php

namespace ChristianHnl\PowerMetrics\Filament\Admin\Widgets;

use ChristianHnl\PowerMetrics\Services\PowerStats;
use Filament\Widgets\ChartWidget;

class PowerProfileChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '300s';

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    protected ?string $maxHeight = '320px';

    public function getHeading(): string
    {
        return trans('power-metrics::strings.charts.profile');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        return [
            'labels' => array_map(fn (int $hour) => sprintf('%02d:00', $hour), range(0, 23)),
            'datasets' => [
                [
                    'label' => trans('power-metrics::strings.charts.avg'),
                    'data' => app(PowerStats::class)->hourOfDayProfile(30),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.18)',
                    'borderWidth' => 2.5,
                    'pointRadius' => 0,
                    'fill' => true,
                    'tension' => 0.35,
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
                'x' => ['ticks' => ['maxTicksLimit' => 8, 'maxRotation' => 0, 'autoSkip' => true]],
                'y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => 'W']],
            ],
        ];
    }
}
