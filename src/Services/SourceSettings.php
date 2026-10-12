<?php

namespace ChristianHnl\PowerMetrics\Services;

use ChristianHnl\PowerMetrics\Models\PowerSource;
use Illuminate\Support\Facades\DB;

/**
 * Übersetzt zwischen den Hosts in der Datenbank und dem Formular in den Plugin-Einstellungen.
 */
class SourceSettings
{
    /** Felder, die 1:1 aus dem Formular übernommen werden */
    private const FIELDS = [
        'name', 'enabled', 'node_id',
        'direct_method', 'direct_url', 'direct_username', 'direct_offset_watts',
        'plug_type', 'plug_url', 'plug_json_path', 'multiplier',
        'estimate_fallback', 'idle_watts', 'max_watts',
    ];

    /** Geheimnisse werden nie ins Formular geschickt und nur bei Eingabe überschrieben */
    private const SECRETS = ['direct_password', 'plug_auth'];

    /** @return array<int, array<string, mixed>> */
    public function toFormData(): array
    {
        return PowerSource::query()->orderBy('id')->get()->map(function (PowerSource $source) {
            $data = ['id' => $source->id, 'token' => $source->token, 'regenerate_token' => false];

            foreach (self::FIELDS as $field) {
                $data[$field] = $source->getAttribute($field);
            }

            foreach (self::SECRETS as $secret) {
                $data[$secret] = null;
                $data["has_$secret"] = filled($source->getRawOriginal($secret));
            }

            return $data;
        })->all();
    }

    /** @param  array<int|string, array<string, mixed>>  $hosts */
    public function save(array $hosts): void
    {
        DB::transaction(function () use ($hosts) {
            $keep = [];

            foreach ($hosts as $host) {
                $source = !empty($host['id']) ? PowerSource::query()->find($host['id']) : null;
                $source ??= new PowerSource();

                foreach (self::FIELDS as $field) {
                    if (array_key_exists($field, $host)) {
                        $source->setAttribute($field, $this->normalize($field, $host[$field]));
                    }
                }

                foreach (self::SECRETS as $secret) {
                    if (filled($host[$secret] ?? null)) {
                        $source->setAttribute($secret, $host[$secret]);
                    }
                }

                // Nicht mehr benötigte Zugangsdaten nicht weiter speichern
                if (!$source->direct_method || $source->direct_method === PowerSource::DIRECT_PUSH || $source->direct_method === PowerSource::DIRECT_LOCAL_RAPL) {
                    $source->direct_password = null;
                }

                if (!$source->plug_type) {
                    $source->plug_auth = null;
                }

                if ($source->direct_method === PowerSource::DIRECT_PUSH && !empty($host['regenerate_token'])) {
                    $source->token = PowerSource::generateToken();
                }

                $source->save();
                $keep[] = $source->id;
            }

            // Entfernte Hosts löschen (inkl. Verlauf, per Fremdschlüssel)
            PowerSource::query()->whereNotIn('id', $keep)->get()->each->delete();
        });
    }

    private function normalize(string $field, mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        return match ($field) {
            'enabled', 'estimate_fallback' => (bool) $value,
            'node_id' => filled($value) ? (int) $value : null,
            'idle_watts', 'max_watts' => filled($value) ? (float) $value : null,
            'direct_offset_watts' => filled($value) ? (float) $value : 0.0,
            'multiplier' => filled($value) && (float) $value > 0 ? (float) $value : 1.0,
            default => filled($value) ? $value : null,
        };
    }
}
