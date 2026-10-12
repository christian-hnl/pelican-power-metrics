<?php

namespace ChristianHnl\PowerMetrics\Filament\Admin\Widgets;

use ChristianHnl\PowerMetrics\Console\Commands\CollectPowerCommand;
use ChristianHnl\PowerMetrics\Models\PowerSource;
use ChristianHnl\PowerMetrics\Services\PowerStats;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Zeigt, woher die Werte kommen (gemessen/geschätzt) und warum ggf. nichts angezeigt wird. */
class PowerStatusWidget extends Widget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'power-metrics::widgets.power-status';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $this->collectIfSchedulerMissing();

        $sources = PowerSource::query()->with('node')->orderBy('name')->get();

        $lastCollect = Cache::get(CollectPowerCommand::HEARTBEAT_KEY);
        $collectorRunning = is_int($lastCollect) && now()->getTimestamp() - $lastCollect <= 180;

        $issues = [];

        if (!$collectorRunning) {
            $issues[] = [
                'color' => 'danger',
                'icon' => 'tabler-clock-exclamation',
                'heading' => trans('power-metrics::strings.status_widget.collector_heading'),
                'description' => trans('power-metrics::strings.status_widget.collector_description', ['path' => base_path('artisan')])
                    . ' ' . trans('power-metrics::strings.status_widget.collector_page_fallback'),
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

    /**
     * Notlösung ohne Cron: Solange diese Seite offen ist, stößt sie die Erfassung selbst an (alle ~60 s).
     * Ersetzt den Scheduler nicht – bei geschlossener Seite entstehen Lücken –, zeigt aber sofort Werte.
     */
    private function collectIfSchedulerMissing(): void
    {
        $lastRun = Cache::get(CollectPowerCommand::ANY_RUN_KEY);

        if (is_int($lastRun) && now()->getTimestamp() - $lastRun < 55) {
            return;
        }

        // Nur eine Anfrage gleichzeitig darf erfassen
        if (!Cache::add('power-metrics.page_collect_lock', true, 50)) {
            return;
        }

        try {
            Artisan::call('power-metrics:collect', ['--from-page' => true]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
