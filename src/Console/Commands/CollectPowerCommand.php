<?php

namespace ChristianHnl\PowerMetrics\Console\Commands;

use App\Models\Node;
use ChristianHnl\PowerMetrics\Models\PowerSource;
use ChristianHnl\PowerMetrics\Services\PowerEstimator;
use ChristianHnl\PowerMetrics\Services\PowerRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class CollectPowerCommand extends Command
{
    public const HEARTBEAT_KEY = 'power-metrics.last_collect';

    protected $signature = 'power-metrics:collect';

    protected $description = 'Liest alle Stromquellen aus (Messung) und schätzt bei Ausfall anhand der Node-Auslastung';

    public function handle(PowerRecorder $recorder, PowerEstimator $estimator): int
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->getTimestamp());

        $this->createDefaultSources();

        $sources = PowerSource::query()->with('node')->where('enabled', true)->get();

        foreach ($sources as $source) {
            try {
                $this->collect($source, $recorder, $estimator);
            } catch (Throwable $exception) {
                $this->markError($source, $exception->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function collect(PowerSource $source, PowerRecorder $recorder, PowerEstimator $estimator): void
    {
        // 1. Messen, wo möglich
        if ($source->type === PowerSource::TYPE_HTTP_JSON) {
            try {
                $recorder->record($source, $this->fetch($source));

                return;
            } catch (Throwable $exception) {
                $this->markError($source, $exception->getMessage());
            }
        }

        // Push-Quellen liefern selbst; nur einspringen, wenn sie verstummt sind
        if ($source->type === PowerSource::TYPE_PUSH && $source->isMeasuring()) {
            return;
        }

        // 2. Fallback: schätzen
        if (!$source->canEstimate()) {
            return;
        }

        $watts = $estimator->estimate($source);

        if ($watts === null) {
            // Einen vorhandenen Messfehler nicht überschreiben, der ist aussagekräftiger
            if (!$source->last_error || $source->type === PowerSource::TYPE_ESTIMATE) {
                $this->markError($source, trans('power-metrics::strings.node_unreachable'));
            }

            return;
        }

        $recorder->record($source, $watts, estimated: true);

        if ($source->type === PowerSource::TYPE_ESTIMATE && $source->last_error) {
            $source->forceFill(['last_error' => null])->save();
        }
    }

    /**
     * Ohne jede Quelle gäbe es nichts zu sehen: einmalig je Node eine Schätz-Quelle anlegen.
     * Wer eine Steckdose o. ä. hat, trägt sie bei dieser Quelle nach (Typ ändern) oder legt eine neue an.
     */
    private function createDefaultSources(): void
    {
        if (PowerSource::query()->exists()) {
            return;
        }

        foreach (Node::all() as $node) {
            PowerSource::query()->create([
                'name' => $node->name,
                'type' => PowerSource::TYPE_ESTIMATE,
                'enabled' => true,
                'node_id' => $node->id,
            ]);
        }
    }

    private function markError(PowerSource $source, string $message): void
    {
        $source->forceFill(['last_error' => mb_substr($message, 0, 250)])->save();

        $this->warn("{$source->name}: {$message}");
    }

    private function fetch(PowerSource $source): float
    {
        if (!$source->url) {
            throw new RuntimeException('Keine URL konfiguriert.');
        }

        $request = Http::timeout(5)->connectTimeout(3)->acceptJson();

        // Entweder "Bearer xyz" / "Basic ..." oder "Header-Name: wert"
        if ($source->auth_header) {
            if (str_contains($source->auth_header, ':')) {
                [$name, $value] = array_map('trim', explode(':', $source->auth_header, 2));
                $request = $request->withHeaders([$name => $value]);
            } else {
                $request = $request->withHeaders(['Authorization' => trim($source->auth_header)]);
            }
        }

        $response = $request->get($source->url)->throw();

        $value = $source->json_path ? data_get($response->json(), $source->json_path) : $response->body();

        if (is_string($value)) {
            $value = trim($value);
        }

        if (!is_numeric($value)) {
            throw new RuntimeException('Wert unter "' . ($source->json_path ?: 'Antwort') . '" ist keine Zahl.');
        }

        return (float) $value * ($source->multiplier ?: 1.0);
    }
}
