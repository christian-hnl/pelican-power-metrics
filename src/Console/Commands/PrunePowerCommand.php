<?php

namespace ChristianHnl\PowerMetrics\Console\Commands;

use ChristianHnl\PowerMetrics\Services\PowerRecorder;
use Illuminate\Console\Command;

class PrunePowerCommand extends Command
{
    protected $signature = 'power-metrics:prune';

    protected $description = 'Löscht alte Power-Metrics-Messwerte gemäß Aufbewahrungsdauer';

    public function handle(PowerRecorder $recorder): int
    {
        $recorder->prune();

        return self::SUCCESS;
    }
}
