<?php

namespace ChristianHnl\PowerMetrics\Services;

use Carbon\CarbonInterface;
use ChristianHnl\PowerMetrics\Models\PowerHourlyStat;
use ChristianHnl\PowerMetrics\Models\PowerSample;
use ChristianHnl\PowerMetrics\Models\PowerSource;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PowerRecorder
{
    /** Obergrenze zur Abwehr offensichtlicher Fehlwerte (1 MW) */
    public const MAX_WATTS = 1_000_000;

    /**
     * Speichert einen Messwert und führt die Stunden-Aggregation sowie die kWh-Integration nach.
     *
     * @return bool false, wenn der Wert verworfen wurde (z. B. älter als der letzte Messwert)
     */
    public function record(PowerSource $source, float $watts, ?CarbonInterface $at = null): bool
    {
        if (!is_finite($watts) || $watts < 0 || $watts > self::MAX_WATTS) {
            throw new InvalidArgumentException('Ungültiger Leistungswert: ' . $watts);
        }

        $at = ($at ?? now())->copy()->utc();

        return DB::transaction(function () use ($source, $watts, $at) {
            /** @var PowerSource $source */
            $source = PowerSource::query()->lockForUpdate()->findOrFail($source->id);

            $energyWh = 0.0;

            if ($source->last_sample_at !== null && $source->last_watts !== null) {
                $seconds = $at->getTimestamp() - $source->last_sample_at->getTimestamp();

                // Veraltete oder doppelte Messwerte ignorieren, damit die Zeitachse monoton bleibt
                if ($seconds <= 0) {
                    return false;
                }

                // Trapezregel; bei großen Lücken (Ausfall) wird nichts hochgerechnet
                if ($seconds <= (int) config('power-metrics.max_gap_seconds', 900)) {
                    $energyWh = (($source->last_watts + $watts) / 2) * ($seconds / 3600);
                }
            }

            PowerSample::query()->create([
                'power_source_id' => $source->id,
                'watts' => $watts,
                'recorded_at' => $at,
            ]);

            $hour = $at->copy()->startOfHour();

            $stat = PowerHourlyStat::query()->firstOrNew([
                'power_source_id' => $source->id,
                'hour' => $hour,
            ]);

            $isNew = !$stat->exists;

            $stat->samples = ($stat->samples ?? 0) + 1;
            $stat->sum_watts = ($stat->sum_watts ?? 0) + $watts;
            $stat->min_watts = $isNew ? $watts : min($stat->min_watts, $watts);
            $stat->max_watts = $isNew ? $watts : max($stat->max_watts, $watts);
            $stat->energy_wh = ($stat->energy_wh ?? 0) + $energyWh;
            $stat->save();

            $source->forceFill([
                'last_watts' => $watts,
                'last_sample_at' => $at,
                'last_error' => null,
            ])->save();

            return true;
        });
    }

    /** Entfernt alte Rohwerte und (optional) sehr alte Stunden-Aggregate. */
    public function prune(): void
    {
        $rawHours = max(1, (int) config('power-metrics.raw_retention_hours', 72));
        PowerSample::query()->where('recorded_at', '<', now()->subHours($rawHours))->delete();

        $days = (int) config('power-metrics.retention_days', 730);
        if ($days > 0) {
            PowerHourlyStat::query()->where('hour', '<', now()->subDays($days))->delete();
        }
    }
}
