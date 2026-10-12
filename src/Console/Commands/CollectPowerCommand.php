<?php

namespace ChristianHnl\PowerMetrics\Console\Commands;

use App\Models\Node;
use ChristianHnl\PowerMetrics\Models\PowerSource;
use ChristianHnl\PowerMetrics\Services\PowerCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CollectPowerCommand extends Command
{
    /** Letzter Lauf über den Scheduler (Cron) */
    public const HEARTBEAT_KEY = 'power-metrics.last_collect';

    /** Letzter Lauf überhaupt (auch über die geöffnete Seite) */
    public const ANY_RUN_KEY = 'power-metrics.last_any_collect';

    protected $signature = 'power-metrics:collect {--from-page : Von der Stromverbrauch-Seite angestoßen (kein Scheduler)}';

    protected $description = 'Ermittelt die Leistung aller Hosts: direkte Messung → Steckdose → Schätzung';

    public function handle(PowerCollector $collector): int
    {
        if (!$this->option('from-page')) {
            Cache::forever(self::HEARTBEAT_KEY, now()->getTimestamp());
        }

        Cache::forever(self::ANY_RUN_KEY, now()->getTimestamp());

        $this->createDefaultSources();

        foreach (PowerSource::query()->with('node')->where('enabled', true)->get() as $source) {
            try {
                $method = $collector->collect($source);
            } catch (Throwable $exception) {
                report($exception);
                $method = null;
            }

            $this->line(sprintf('%s: %s%s', $source->name, $method ?? '–', $source->last_error ? " ({$source->last_error})" : ''));
        }

        return self::SUCCESS;
    }

    /**
     * Ohne jeden Host gäbe es nichts zu sehen: einmalig je Node einen Host mit Schätzung anlegen.
     * Messung (direkt/Steckdose) wird danach in den Plugin-Einstellungen ergänzt.
     */
    private function createDefaultSources(): void
    {
        if (PowerSource::query()->exists()) {
            return;
        }

        foreach (Node::all() as $node) {
            PowerSource::query()->create([
                'name' => $node->name,
                'enabled' => true,
                'node_id' => $node->id,
                'estimate_fallback' => true,
            ]);
        }
    }
}
