<?php

namespace ChristianHnl\PowerMetrics\Filament\Admin\Widgets;

use ChristianHnl\PowerMetrics\Services\PowerStats;
use Filament\Widgets\ChartWidget;

class PowerHistoryChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '360px';

    public ?string $filter = '24h';

    public function getHeading(): string
    {
        return trans('power-metrics::strings.charts.history');
    }

    protected function getFilters(): ?array
    {
        return collect(array_keys(PowerStats::RANGES))
            ->mapWithKeys(fn (string $range) => [$range => trans("power-metrics::strings.ranges.$range")])
            ->all();
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $series = app(PowerStats::class)->series($this->filter ?? '24h');

        return [
            'labels' => $series['labels'],
            'datasets' => [
                [
                    'label' => trans('power-metrics::strings.charts.max'),
                    'data' => $series['max'],
                    'borderColor' => '#ef4444',
                    'borderDash' => [6, 4],
                    'borderWidth' => 1.5,
                    'pointRadius' => 0,
                    'fill' => false,
                    'tension' => 0.25,
                ],
                [
                    'label' => trans('power-metrics::strings.charts.avg'),
                    'data' => $series['avg'],
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.15)',
                    'borderWidth' => 2.5,
                    'pointRadius' => 0,
                    'fill' => true,
                    'tension' => 0.25,
                ],
                [
                    'label' => trans('power-metrics::strings.charts.min'),
                    'data' => $series['min'],
                    'borderColor' => '#10b981',
                    'borderDash' => [6, 4],
                    'borderWidth' => 1.5,
                    'pointRadius' => 0,
                    'fill' => false,
                    'tension' => 0.25,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => [
                'legend' => ['position' => 'bottom'],
            ],
            'scales' => [
                'x' => ['ticks' => ['maxTicksLimit' => 8, 'maxRotation' => 0, 'autoSkip' => true]],
                'y' => [
                    'beginAtZero' => true,
                    'title' => ['display' => true, 'text' => 'W'],
                ],
            ],
        ];
    }
}
