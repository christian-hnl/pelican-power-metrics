<?php

namespace ChristianHnl\PowerMetrics\Services;

use ChristianHnl\PowerMetrics\Models\PowerSource;
use Throwable;

/**
 * Mess-Kette je Host: 1. direkte Messung → 2. Steckdose → 3. Schätzung.
 * Die erste Stufe mit einem gültigen Wert wird gespeichert; Fehler der höheren Stufen bleiben als Hinweis sichtbar.
 */
class PowerCollector
{
    public function __construct(
        private PowerRecorder $recorder,
        private PowerReader $reader,
        private PowerEstimator $estimator,
    ) {}

    /** @return ?string verwendete Methode oder null, wenn kein Wert ermittelt werden konnte */
    public function collect(PowerSource $source): ?string
    {
        $errors = [];

        // 1. Direkte Messung
        if ($source->hasDirect()) {
            if ($source->isPushActive()) {
                // Das Skript auf dem Host liefert selbst – nichts weiter zu tun
                $this->setError($source, []);

                return PowerSource::METHOD_DIRECT;
            }

            if ($source->direct_method === PowerSource::DIRECT_PUSH) {
                $errors[] = trans('power-metrics::strings.chain.direct') . ': ' . trans('power-metrics::strings.errors.push_silent');
            } else {
                try {
                    $this->recorder->record($source, $this->reader->readDirect($source), method: PowerSource::METHOD_DIRECT);
                    $this->setError($source, []);

                    return PowerSource::METHOD_DIRECT;
                } catch (Throwable $exception) {
                    $errors[] = trans('power-metrics::strings.chain.direct') . ': ' . $exception->getMessage();
                }
            }
        }

        // 2. Steckdose
        if ($source->hasPlug()) {
            try {
                $this->recorder->record($source, $this->reader->readPlug($source), method: PowerSource::METHOD_PLUG);
                $this->setError($source, $errors);

                return PowerSource::METHOD_PLUG;
            } catch (Throwable $exception) {
                $errors[] = trans('power-metrics::strings.chain.plug') . ': ' . $exception->getMessage();
            }
        }

        // 3. Schätzung
        if ($source->canEstimate()) {
            $watts = $this->estimator->estimate($source);

            if ($watts !== null) {
                $this->recorder->record($source, $watts, method: PowerSource::METHOD_ESTIMATE);
                $this->setError($source, $errors);

                return PowerSource::METHOD_ESTIMATE;
            }

            $errors[] = trans('power-metrics::strings.chain.estimate') . ': ' . trans('power-metrics::strings.node_unreachable');
        }

        if (!$source->hasDirect() && !$source->hasPlug() && !$source->canEstimate()) {
            $errors[] = trans('power-metrics::strings.errors.nothing_configured');
        }

        $this->setError($source, $errors);

        return null;
    }

    /** @param  string[]  $errors */
    private function setError(PowerSource $source, array $errors): void
    {
        $message = $errors ? mb_substr(implode(' · ', $errors), 0, 250) : null;

        PowerSource::query()->whereKey($source->id)->update(['last_error' => $message]);
        $source->last_error = $message;
    }
}
