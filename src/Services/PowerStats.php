<?php

namespace ChristianHnl\PowerMetrics\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use ChristianHnl\PowerMetrics\Models\PowerHourlyStat;
use ChristianHnl\PowerMetrics\Models\PowerSample;
use ChristianHnl\PowerMetrics\Models\PowerSource;

/**
 * Berechnet alle Kennzahlen und Verläufe.
 *
 * Mehrere Quellen werden pro Stunde bzw. pro Zeitfenster zur Gesamtleistung addiert.
 * Alle Rechenwerte basieren auf den Stunden-Aggregaten, daher bleibt auch "Monat/Jahr" billig.
 */
class PowerStats
{
    /**
     * Verfügbare Zeiträume für den Verlauf: [Quelle, Dauer in Sekunden, Bucket-Größe in Sekunden, Labelformat]
     *
     * @var array<string, array{0: 'raw'|'hourly', 1: int, 2: int, 3: string}>
     */
    public const RANGES = [
        '1h' => ['raw', 3600, 60, 'H:i'],
        '6h' => ['raw', 21600, 300, 'H:i'],
        '24h' => ['raw', 86400, 900, 'H:i'],
        '7d' => ['hourly', 604800, 3600, 'd.m. H:i'],
        '30d' => ['hourly', 2592000, 21600, 'd.m. H:i'],
        '90d' => ['hourly', 7776000, 86400, 'd.m.'],
        '1y' => ['hourly', 31536000, 86400, 'd.m.y'],
    ];

    public function timezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    public function now(): Carbon
    {
        return Carbon::now($this->timezone());
    }

    /** @return array{watts: ?float, online: int, total: int} */
    public function current(): array
    {
        $sources = PowerSource::query()->where('enabled', true)->get();
        $online = $sources->filter(fn (PowerSource $source) => $source->isOnline());

        return [
            'watts' => $online->isEmpty() ? null : (float) $online->sum('last_watts'),
            'online' => $online->count(),
            'total' => $sources->count(),
        ];
    }

    /**
     * Kennzahlen für einen Zeitraum.
     *
     * @return array{kwh: float, avg: ?float, peak: ?float, peak_at: ?Carbon, low: ?float, low_at: ?Carbon, hours: int}
     */
    public function summary(CarbonInterface $from, CarbonInterface $to): array
    {
        $hours = $this->hourTotals($from, $to);

        $kwh = 0.0;
        $weighted = 0.0;
        $weights = 0;
        $peak = $low = $peakAt = $lowAt = null;

        foreach ($hours as $timestamp => $hour) {
            $kwh += $hour['wh'] / 1000;
            $weighted += $hour['avg'] * $hour['weight'];
            $weights += $hour['weight'];

            if ($peak === null || $hour['max'] > $peak) {
                $peak = $hour['max'];
                $peakAt = $timestamp;
            }

            if ($low === null || $hour['min'] < $low) {
                $low = $hour['min'];
                $lowAt = $timestamp;
            }
        }

        $tz = $this->timezone();

        return [
            'kwh' => $kwh,
            'avg' => $weights > 0 ? $weighted / $weights : null,
            'peak' => $peak,
            'peak_at' => $peakAt !== null ? Carbon::createFromTimestamp($peakAt, $tz) : null,
            'low' => $low,
            'low_at' => $lowAt !== null ? Carbon::createFromTimestamp($lowAt, $tz) : null,
            'hours' => count($hours),
        ];
    }

    /** Hochrechnung auf den ganzen Monat anhand der durchschnittlichen Leistung. */
    public function monthForecastKwh(): ?float
    {
        $start = $this->now()->startOfMonth();
        $summary = $this->summary($start, $start->copy()->addMonth());

        if ($summary['avg'] === null || $summary['hours'] < 1) {
            return null;
        }

        return $summary['avg'] * $start->daysInMonth * 24 / 1000;
    }

    /**
     * Verlauf für das Hauptdiagramm.
     *
     * @return array{labels: string[], avg: float[], min: float[], max: float[]}
     */
    public function series(string $range): array
    {
        [$source, $duration, $bucket, $format] = self::RANGES[$range] ?? self::RANGES['24h'];

        $tz = $this->timezone();
        $since = now()->utc()->subSeconds($duration);

        /** @var array<int, array{sum: float, min: float, max: float, weight: int}> $buckets */
        $buckets = [];

        if ($source === 'raw') {
            // Pro Bucket und Quelle mitteln, danach über alle Quellen addieren
            $perSource = [];

            PowerSample::query()
                ->where('recorded_at', '>=', $since)
                ->orderBy('recorded_at')
                ->get(['power_source_id', 'watts', 'recorded_at'])
                ->each(function (PowerSample $sample) use (&$perSource, $bucket) {
                    $key = intdiv($sample->recorded_at->getTimestamp(), $bucket) * $bucket;
                    $entry = &$perSource[$key][$sample->power_source_id];

                    $entry['sum'] = ($entry['sum'] ?? 0) + $sample->watts;
                    $entry['count'] = ($entry['count'] ?? 0) + 1;
                    $entry['min'] = isset($entry['min']) ? min($entry['min'], $sample->watts) : $sample->watts;
                    $entry['max'] = isset($entry['max']) ? max($entry['max'], $sample->watts) : $sample->watts;
                });

            ksort($perSource);

            foreach ($perSource as $key => $sources) {
                $buckets[$key] = ['sum' => 0.0, 'min' => 0.0, 'max' => 0.0, 'weight' => 1];

                foreach ($sources as $entry) {
                    $buckets[$key]['sum'] += $entry['sum'] / $entry['count'];
                    $buckets[$key]['min'] += $entry['min'];
                    $buckets[$key]['max'] += $entry['max'];
                }
            }
        } else {
            foreach ($this->hourTotals($since, now()->addHour()) as $timestamp => $hour) {
                $key = $this->bucketKey($timestamp, $bucket);

                if (!isset($buckets[$key])) {
                    $buckets[$key] = ['sum' => 0.0, 'min' => $hour['min'], 'max' => $hour['max'], 'weight' => 0];
                }

                $buckets[$key]['sum'] += $hour['avg'] * $hour['weight'];
                $buckets[$key]['weight'] += $hour['weight'];
                $buckets[$key]['min'] = min($buckets[$key]['min'], $hour['min']);
                $buckets[$key]['max'] = max($buckets[$key]['max'], $hour['max']);
            }

            ksort($buckets);
        }

        $result = ['labels' => [], 'avg' => [], 'min' => [], 'max' => []];

        foreach ($buckets as $key => $data) {
            $result['labels'][] = Carbon::createFromTimestamp($key, $tz)->format($format);
            $result['avg'][] = round($data['sum'] / max(1, $data['weight']), 1);
            $result['min'][] = round($data['min'], 1);
            $result['max'][] = round($data['max'], 1);
        }

        return $result;
    }

    /**
     * kWh pro Tag (lokale Tage), lückenlos.
     *
     * @return array{labels: string[], kwh: float[]}
     */
    public function dailyEnergy(CarbonInterface $from, CarbonInterface $to): array
    {
        $tz = $this->timezone();
        $perDay = [];

        foreach ($this->hourTotals($from, $to) as $timestamp => $hour) {
            $day = Carbon::createFromTimestamp($timestamp, $tz)->format('Y-m-d');
            $perDay[$day] = ($perDay[$day] ?? 0) + $hour['wh'] / 1000;
        }

        $labels = [];
        $values = [];

        for ($day = $from->copy()->setTimezone($tz)->startOfDay(); $day->lt($to); $day->addDay()) {
            $labels[] = $day->format('d.m.');
            $values[] = round($perDay[$day->format('Y-m-d')] ?? 0, 3);
        }

        return ['labels' => $labels, 'kwh' => $values];
    }

    /**
     * Durchschnittliche Leistung je Tageszeit (0-23 Uhr, lokal) über die letzten Tage.
     *
     * @return float[]
     */
    public function hourOfDayProfile(int $days = 30): array
    {
        $tz = $this->timezone();
        $sums = array_fill(0, 24, 0.0);
        $weights = array_fill(0, 24, 0);

        foreach ($this->hourTotals(now()->subDays($days), now()->addHour()) as $timestamp => $hour) {
            $h = (int) Carbon::createFromTimestamp($timestamp, $tz)->format('G');
            $sums[$h] += $hour['avg'] * $hour['weight'];
            $weights[$h] += $hour['weight'];
        }

        return array_map(fn (int $h) => $weights[$h] > 0 ? round($sums[$h] / $weights[$h], 1) : 0.0, range(0, 23));
    }

    /**
     * Summiert die Stunden-Aggregate aller Quellen je Stunde.
     *
     * @return array<int, array{avg: float, min: float, max: float, wh: float, weight: int}> Schlüssel: Unix-Timestamp der Stunde
     */
    private function hourTotals(CarbonInterface $from, CarbonInterface $to): array
    {
        $totals = [];

        PowerHourlyStat::query()
            ->where('hour', '>=', $from->copy()->utc())
            ->where('hour', '<', $to->copy()->utc())
            ->orderBy('hour')
            ->get()
            ->each(function (PowerHourlyStat $stat) use (&$totals) {
                if ($stat->samples < 1) {
                    return;
                }

                $key = $stat->hour->getTimestamp();

                $totals[$key] ??= ['avg' => 0.0, 'min' => 0.0, 'max' => 0.0, 'wh' => 0.0, 'weight' => 0];
                $totals[$key]['avg'] += $stat->sum_watts / $stat->samples;
                $totals[$key]['min'] += $stat->min_watts;
                $totals[$key]['max'] += $stat->max_watts;
                $totals[$key]['wh'] += $stat->energy_wh;
                $totals[$key]['weight'] = max($totals[$key]['weight'], $stat->samples);
            });

        ksort($totals);

        return $totals;
    }

    private function bucketKey(int $timestamp, int $size): int
    {
        if ($size >= 86400) {
            return Carbon::createFromTimestamp($timestamp, $this->timezone())->startOfDay()->getTimestamp();
        }

        return intdiv($timestamp, $size) * $size;
    }

    // ---- Formatierung -------------------------------------------------------------------------

    public static function number(float $value, int $decimals = 0): string
    {
        $german = str_starts_with(app()->getLocale(), 'de');

        return number_format($value, $decimals, $german ? ',' : '.', $german ? '.' : ',');
    }

    public static function watts(?float $watts): string
    {
        if ($watts === null) {
            return '–';
        }

        return $watts >= 1000
            ? self::number($watts / 1000, 2) . ' kW'
            : self::number($watts, $watts < 100 ? 1 : 0) . ' W';
    }

    public static function kwh(float $kwh): string
    {
        return self::number($kwh, $kwh < 10 ? 2 : 1) . ' kWh';
    }

    public static function cost(float $kwh): string
    {
        return self::number($kwh * (float) config('power-metrics.price_per_kwh', 0), 2) . ' ' . config('power-metrics.currency', '€');
    }
}
