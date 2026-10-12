<?php

namespace ChristianHnl\PowerMetrics\Filament\Admin\Widgets;

use ChristianHnl\PowerMetrics\Console\Commands\CollectPowerCommand;
use ChristianHnl\PowerMetrics\Models\PowerSource;
use ChristianHnl\PowerMetrics\Services\PowerStats;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

/** Zeigt, woher die Werte kommen (gemessen/geschätzt) und warum ggf. nichts angezeigt wird. */
class PowerStatusWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'power-metrics::widgets.power-status';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $sources = PowerSource::query()->with('node')->orderBy('name')->get();

        $lastCollect = Cache::get(CollectPowerCommand::HEARTBEAT_KEY);
        $collectorRunning = is_int($lastCollect) && now()->getTimestamp() - $lastCollect <= 180;

        $issues = [];

        if (!$collectorRunning) {
            $issues[] = [
                'color' => 'danger',
                'icon' => 'tabler-clock-exclamation',
                'heading' => trans('power-metrics::strings.status_widget.collector_heading'),
                'description' => trans('power-metrics::strings.status_widget.collector_description', ['path' => base_path('artisan')]),
            ];
        }

        if ($sources->isEmpty()) {
            $issues[] = [
                'color' => 'warning',
                'icon' => 'tabler-plug-off',
                'heading' => trans('power-metrics::strings.no_sources'),
                'description' => trans('power-metrics::strings.status_widget.no_sources_description'),
            ];
        } elseif ($collectorRunning && $sources->where('enabled', true)->every(fn (PowerSource $source) => !$source->isOnline())) {
            $issues[] = [
                'color' => 'warning',
                'icon' => 'tabler-plug-x',
                'heading' => trans('power-metrics::strings.status_widget.all_offline_heading'),
                'description' => trans('power-metrics::strings.status_widget.all_offline_description'),
            ];
        }

        return [
            'issues' => $issues,
            'sources' => $sources->map(fn (PowerSource $source) => [
                'name' => $source->name,
                'type' => PowerSource::types()[$source->type] ?? $source->type,
                'node' => $source->node?->name,
                'mode' => $source->enabled ? $source->mode() : 'disabled',
                'watts' => $source->isOnline() ? PowerStats::watts($source->last_watts) : null,
                'error' => $source->last_error,
                'hint' => $source->type !== PowerSource::TYPE_ESTIMATE && !$source->canEstimate()
                    ? trans('power-metrics::strings.status_widget.no_fallback')
                    : null,
            ])->all(),
        ];
    }
}
