<?php

namespace ChristianHnl\PowerMetrics\Services;

use ChristianHnl\PowerMetrics\Models\PowerSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Liest echte Messwerte aus. Jede Methode liefert Watt oder wirft eine RuntimeException mit verständlichem Grund.
 */
class PowerReader
{
    /** Übliche Redfish-Pfade: HPE iLO/Supermicro/Lenovo, Dell iDRAC */
    public const REDFISH_PATHS = [
        '/redfish/v1/Chassis/1/Power',
        '/redfish/v1/Chassis/System.Embedded.1/Power',
        '/redfish/v1/Chassis/Self/Power',
    ];

    public const REDFISH_JSON_PATH = 'PowerControl.0.PowerConsumedWatts';

    /** Stufe 1: direkte Messung (Push wird nicht gelesen, sondern geliefert). */
    public function readDirect(PowerSource $source): float
    {
        return match ($source->direct_method) {
            PowerSource::DIRECT_REDFISH => $this->readRedfish($source),
            PowerSource::DIRECT_LOCAL_RAPL => $this->readLocalRapl($source) + $source->direct_offset_watts,
            PowerSource::DIRECT_PUSH => throw new RuntimeException(trans('power-metrics::strings.errors.push_silent')),
            default => throw new RuntimeException('Unbekannte Messart: ' . $source->direct_method),
        };
    }

    /** Stufe 2: Steckdose */
    public function readPlug(PowerSource $source): float
    {
        $preset = PowerSource::PLUG_PRESETS[$source->plug_type] ?? null;
        if ($preset === null) {
            throw new RuntimeException('Unbekannter Steckdosen-Typ: ' . $source->plug_type);
        }

        $address = trim((string) $source->plug_url);
        if ($address === '') {
            throw new RuntimeException(trans('power-metrics::strings.errors.plug_address_missing'));
        }

        // Volle URL wird unverändert genutzt, sonst die Vorlage mit IP/Hostname füllen
        $url = str_starts_with($address, 'http://') || str_starts_with($address, 'https://') || $preset['url'] === null
            ? $address
            : str_replace('{host}', $address, $preset['url']);

        $path = $source->plug_type === PowerSource::PLUG_CUSTOM || $source->plug_type === PowerSource::PLUG_HOME_ASSISTANT
            ? ($source->plug_json_path ?: $preset['path'])
            : $preset['path'];

        $multiplier = $source->plug_type === PowerSource::PLUG_CUSTOM ? ($source->multiplier ?: 1.0) : 1.0;

        return $this->readJson($url, $path, $source->plug_auth) * $multiplier;
    }

    public function readRedfish(PowerSource $source): float
    {
        $address = rtrim(trim((string) $source->direct_url), '/');
        if ($address === '') {
            throw new RuntimeException(trans('power-metrics::strings.errors.bmc_address_missing'));
        }

        $base = str_starts_with($address, 'http://') || str_starts_with($address, 'https://') ? $address : "https://$address";
        $urls = str_contains(parse_url($base, PHP_URL_PATH) ?? '', '/redfish/')
            ? [$base]
            : array_map(fn (string $path) => $base . $path, self::REDFISH_PATHS);

        $lastError = null;

        foreach ($urls as $url) {
            try {
                $request = Http::timeout(8)->connectTimeout(4)->acceptJson()
                    ->withoutVerifying(); // BMCs nutzen fast immer selbstsignierte Zertifikate

                if ($source->direct_username) {
                    $request = $request->withBasicAuth($source->direct_username, (string) $source->direct_password);
                }

                $response = $request->get($url);

                if ($response->status() === 401 || $response->status() === 403) {
                    throw new RuntimeException('BMC: Anmeldung fehlgeschlagen (HTTP ' . $response->status() . ')');
                }

                $value = data_get($response->throw()->json(), self::REDFISH_JSON_PATH);

                if (is_numeric($value)) {
                    return (float) $value + $source->direct_offset_watts;
                }

                $lastError = "BMC: kein Wert unter $url";
            } catch (Throwable $exception) {
                if (str_starts_with($exception->getMessage(), 'BMC: Anmeldung')) {
                    throw $exception;
                }

                $lastError = 'BMC: ' . $exception->getMessage();
            }
        }

        throw new RuntimeException((string) $lastError);
    }

    /**
     * RAPL (Intel/AMD) auf dem Rechner, auf dem das Panel läuft. Misst nur CPU-Package (+ ggf. DRAM),
     * nicht das ganze System. Braucht zwei Läufe (Differenz der Energiezähler).
     */
    public function readLocalRapl(PowerSource $source): float
    {
        $base = (string) config('power-metrics.powercap_path', '/sys/class/powercap');
        $total = 0;
        $range = 0;
        $found = false;

        foreach (glob($base . '/intel-rapl:*') ?: [] as $dir) {
            if (!preg_match('/intel-rapl:\d+$/', $dir)) {
                continue; // Subzonen sind im Package enthalten
            }

            $energy = @file_get_contents($dir . '/energy_uj');
            if ($energy === false) {
                throw new RuntimeException(trans('power-metrics::strings.errors.rapl_unreadable'));
            }

            $found = true;
            $total += (int) trim($energy);
            $range += (int) trim((string) @file_get_contents($dir . '/max_energy_range_uj'));
        }

        if (!$found) {
            throw new RuntimeException(trans('power-metrics::strings.errors.rapl_missing'));
        }

        $key = "power-metrics.rapl.$source->id";
        $now = microtime(true);
        $previous = Cache::get($key);
        Cache::put($key, ['uj' => $total, 't' => $now], 600);

        if (!is_array($previous) || $now - $previous['t'] <= 0 || $now - $previous['t'] > 600) {
            throw new RuntimeException(trans('power-metrics::strings.errors.rapl_warming_up'));
        }

        $delta = $total - $previous['uj'];
        if ($delta < 0) {
            $delta += $range; // Zählerüberlauf
        }

        return round($delta / 1_000_000 / ($now - $previous['t']), 1);
    }

    public function readJson(string $url, ?string $path, ?string $auth): float
    {
        $request = Http::timeout(5)->connectTimeout(3)->acceptJson();

        // Entweder "Bearer xyz" / "Basic ..." / nur der Token oder "Header-Name: wert"
        if ($auth) {
            $auth = trim($auth);

            if (preg_match('/^[A-Za-z0-9-]+:\s/', $auth)) {
                [$name, $value] = array_map('trim', explode(':', $auth, 2));
                $request = $request->withHeaders([$name => $value]);
            } elseif (preg_match('/^(Bearer|Basic) /i', $auth)) {
                $request = $request->withHeaders(['Authorization' => $auth]);
            } else {
                $request = $request->withToken($auth);
            }
        }

        $response = $request->get($url)->throw();

        $value = $path ? data_get($response->json(), $path) : $response->body();

        if (is_string($value)) {
            $value = trim($value);
        }

        if (!is_numeric($value)) {
            throw new RuntimeException('Wert unter "' . ($path ?: 'Antwort') . '" ist keine Zahl.');
        }

        return (float) $value;
    }
}
