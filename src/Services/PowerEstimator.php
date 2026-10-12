<?php

namespace ChristianHnl\PowerMetrics\Services;

use App\Models\Node;
use ChristianHnl\PowerMetrics\Models\PowerSource;

/**
 * Schätzt die Leistungsaufnahme aus der CPU-Auslastung, die Wings an das Panel meldet:
 *
 *     Watt = Leerlauf + (Volllast − Leerlauf) × CPU-Auslastung
 *
 * Das ist nur eine Näherung. Echte Werte liefert eine messende Quelle (Steckdose, IPMI).
 */
class PowerEstimator
{
    public function estimate(PowerSource $source): ?float
    {
        if ($source->node_id === null) {
            return null;
        }

        $node = $source->node;
        if (!$node) {
            return null;
        }

        $load = $this->cpuLoad($node);
        if ($load === null) {
            return null;
        }

        [$idle, $max] = $this->wattRange($source);

        return round($idle + ($max - $idle) * $load, 1);
    }

    /** @return array{0: float, 1: float} [Leerlauf-Watt, Volllast-Watt] */
    public function wattRange(PowerSource $source): array
    {
        $idle = $source->idle_watts ?? (float) config('power-metrics.default_idle_watts', 35);
        $max = $source->max_watts ?? (float) config('power-metrics.default_max_watts', 120);

        return [max(0.0, $idle), max($idle, $max)];
    }

    /** CPU-Auslastung des Nodes zwischen 0 und 1, oder null wenn Wings nicht erreichbar ist. */
    protected function cpuLoad(Node $node): ?float
    {
        $stats = $node->statistics();

        // Node::statistics() liefert bei Fehlern Nullwerte; memory_total = 0 heißt "keine Antwort"
        if (empty($stats['memory_total'])) {
            return null;
        }

        return min(1.0, max(0.0, (float) ($stats['cpu_percent'] ?? 0) / 100));
    }
}
