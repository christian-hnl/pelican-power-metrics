<?php

namespace ChristianHnl\PowerMetrics\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Speichert Zeitstempel immer in UTC und liefert sie in der Panel-Zeitzone zurück.
 * (Laravels Standard-Cast würde den UTC-Wert sonst als Panel-Zeit lesen und um den Offset verschieben.)
 */
class UtcDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value, 'UTC')->setTimezone((string) config('app.timezone', 'UTC'));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
    }
}
