<?php

namespace ChristianHnl\PowerMetrics\Console\Commands;

use ChristianHnl\PowerMetrics\Models\PowerSource;
use ChristianHnl\PowerMetrics\Services\PowerRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class CollectPowerCommand extends Command
{
    protected $signature = 'power-metrics:collect';

    protected $description = 'Fragt alle aktiven HTTP-Stromquellen (Shelly, Tasmota, Home Assistant, ...) ab';

    public function handle(PowerRecorder $recorder): int
    {
        $sources = PowerSource::query()
            ->where('enabled', true)
            ->where('type', PowerSource::TYPE_HTTP_JSON)
            ->get();

        foreach ($sources as $source) {
            try {
                $recorder->record($source, $this->fetch($source));
            } catch (Throwable $exception) {
                $source->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 250)])->save();

                $this->warn("{$source->name}: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function fetch(PowerSource $source): float
    {
        if (!$source->url) {
            throw new \RuntimeException('Keine URL konfiguriert.');
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
            throw new \RuntimeException('Wert unter "' . ($source->json_path ?: 'Antwort') . '" ist keine Zahl.');
        }

        return (float) $value * ($source->multiplier ?: 1.0);
    }
}
