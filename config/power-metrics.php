<?php

return [
    // Strompreis pro kWh
    'price_per_kwh' => (float) env('POWER_METRICS_PRICE_PER_KWH', 0.35),

    // Währungssymbol/-kürzel für die Kostenanzeige
    'currency' => env('POWER_METRICS_CURRENCY', '€'),

    // Wie lange Stunden-Statistiken aufbewahrt werden (Tage)
    'retention_days' => (int) env('POWER_METRICS_RETENTION_DAYS', 730),

    // Wie lange Rohmesswerte (Minutenauflösung) aufbewahrt werden (Stunden)
    'raw_retention_hours' => (int) env('POWER_METRICS_RAW_RETENTION_HOURS', 72),

    // Ab welcher Lücke (Sekunden) zwischen zwei Messwerten keine Energie mehr integriert wird
    'max_gap_seconds' => (int) env('POWER_METRICS_MAX_GAP_SECONDS', 900),

    // Standardwerte für die Schätzung (Fallback), wenn an der Quelle nichts eingetragen ist
    'default_idle_watts' => (float) env('POWER_METRICS_DEFAULT_IDLE_WATTS', 35),
    'default_max_watts' => (float) env('POWER_METRICS_DEFAULT_MAX_WATTS', 120),

    // Nach wie vielen Sekunden ohne Messwert eine Quelle als "offline" gilt
    'stale_after_seconds' => (int) env('POWER_METRICS_STALE_AFTER_SECONDS', 300),
];
