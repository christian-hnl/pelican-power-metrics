<?php

namespace ChristianHnl\PowerMetrics\Models;

use App\Models\Node;
use Carbon\CarbonInterface;
use ChristianHnl\PowerMetrics\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Ein Host (z. B. der Proxmox-Server) mit Mess-Kette:
 *   1. direkte Messung (Push-Skript, BMC/Redfish, lokales RAPL)
 *   2. Steckdose mit Messfunktion (Shelly, Tasmota, Home Assistant, eigene URL)
 *   3. Schätzung aus der CPU-Auslastung des Wings-Nodes
 * Die erste Stufe, die einen Wert liefert, gewinnt.
 *
 * @property int $id
 * @property string $name
 * @property bool $enabled
 * @property ?string $token
 * @property float $multiplier
 * @property ?string $direct_method
 * @property ?string $direct_url
 * @property ?string $direct_username
 * @property ?string $direct_password
 * @property float $direct_offset_watts
 * @property ?string $plug_type
 * @property ?string $plug_url
 * @property ?string $plug_json_path
 * @property ?string $plug_auth
 * @property ?int $node_id
 * @property ?float $idle_watts
 * @property ?float $max_watts
 * @property bool $estimate_fallback
 * @property ?float $last_watts
 * @property ?CarbonInterface $last_sample_at
 * @property bool $last_estimated
 * @property ?string $last_method
 * @property ?CarbonInterface $last_measured_at
 * @property ?CarbonInterface $last_direct_at
 * @property ?string $last_error
 * @property ?Node $node
 */
class PowerSource extends Model
{
    // Stufe 1: direkte Messung
    public const DIRECT_PUSH = 'push';

    public const DIRECT_REDFISH = 'redfish';

    public const DIRECT_LOCAL_RAPL = 'local_rapl';

    // Stufe 2: Steckdose
    public const PLUG_SHELLY_GEN2 = 'shelly_gen2';

    public const PLUG_SHELLY_GEN1 = 'shelly_gen1';

    public const PLUG_TASMOTA = 'tasmota';

    public const PLUG_HOME_ASSISTANT = 'home_assistant';

    public const PLUG_CUSTOM = 'custom';

    /** Vorlagen: {host} wird durch die eingetragene IP/den Hostnamen ersetzt */
    public const PLUG_PRESETS = [
        self::PLUG_SHELLY_GEN2 => ['url' => 'http://{host}/rpc/Switch.GetStatus?id=0', 'path' => 'apower'],
        self::PLUG_SHELLY_GEN1 => ['url' => 'http://{host}/status', 'path' => 'meters.0.power'],
        self::PLUG_TASMOTA => ['url' => 'http://{host}/cm?cmnd=Status%208', 'path' => 'StatusSNS.ENERGY.Power'],
        self::PLUG_HOME_ASSISTANT => ['url' => null, 'path' => 'state'],
        self::PLUG_CUSTOM => ['url' => null, 'path' => null],
    ];

    // Womit der letzte Wert ermittelt wurde
    public const METHOD_DIRECT = 'direct';

    public const METHOD_PLUG = 'plug';

    public const METHOD_ESTIMATE = 'estimate';

    // Anzeige-Zustände
    public const MODE_DIRECT = 'direct';

    public const MODE_PLUG = 'plug';

    public const MODE_ESTIMATED = 'estimated';

    public const MODE_OFFLINE = 'offline';

    protected $fillable = [
        'name',
        'type',
        'enabled',
        'token',
        'multiplier',
        'direct_method',
        'direct_url',
        'direct_username',
        'direct_password',
        'direct_offset_watts',
        'plug_type',
        'plug_url',
        'plug_json_path',
        'plug_auth',
        'node_id',
        'idle_watts',
        'max_watts',
        'estimate_fallback',
    ];

    protected $hidden = [
        'token',
        'auth_header',
        'direct_password',
        'plug_auth',
    ];

    protected $attributes = [
        'type' => 'host',
        'multiplier' => 1,
        'direct_offset_watts' => 0,
        'estimate_fallback' => true,
        'last_estimated' => false,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'bool',
            'multiplier' => 'float',
            'direct_offset_watts' => 'float',
            'direct_password' => 'encrypted',
            'plug_auth' => 'encrypted',
            'node_id' => 'int',
            'idle_watts' => 'float',
            'max_watts' => 'float',
            'estimate_fallback' => 'bool',
            'last_watts' => 'float',
            'last_sample_at' => UtcDateTime::class,
            'last_estimated' => 'bool',
            'last_measured_at' => UtcDateTime::class,
            'last_direct_at' => UtcDateTime::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $source) {
            if ($source->direct_method === self::DIRECT_PUSH && !$source->token) {
                $source->token = self::generateToken();
            }
        });
    }

    public static function generateToken(): string
    {
        return Str::random(40);
    }

    /** @return array<string, string> */
    public static function directMethods(): array
    {
        return [
            self::DIRECT_PUSH => trans('power-metrics::strings.direct.push'),
            self::DIRECT_REDFISH => trans('power-metrics::strings.direct.redfish'),
            self::DIRECT_LOCAL_RAPL => trans('power-metrics::strings.direct.local_rapl'),
        ];
    }

    /** @return array<string, string> */
    public static function plugTypes(): array
    {
        return [
            self::PLUG_SHELLY_GEN2 => 'Shelly Gen2/Gen3 (Plug S, Plus, Pro)',
            self::PLUG_SHELLY_GEN1 => 'Shelly Gen1 (Plug, Plug S)',
            self::PLUG_TASMOTA => 'Tasmota',
            self::PLUG_HOME_ASSISTANT => 'Home Assistant',
            self::PLUG_CUSTOM => trans('power-metrics::strings.plug.custom'),
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function samples(): HasMany
    {
        return $this->hasMany(PowerSample::class);
    }

    public function hourlyStats(): HasMany
    {
        return $this->hasMany(PowerHourlyStat::class);
    }

    public function hasDirect(): bool
    {
        return filled($this->direct_method);
    }

    public function hasPlug(): bool
    {
        return filled($this->plug_type);
    }

    public function canEstimate(): bool
    {
        return $this->estimate_fallback && $this->node_id !== null;
    }

    /** Hat das Push-Skript kürzlich geliefert? Dann ist die direkte Messung aktiv. */
    public function isPushActive(): bool
    {
        return $this->direct_method === self::DIRECT_PUSH
            && $this->last_direct_at !== null
            && $this->last_direct_at->diffInSeconds(now(), true) <= (int) config('power-metrics.push_timeout_seconds', 150);
    }

    public function isOnline(): bool
    {
        return $this->enabled
            && $this->last_sample_at !== null
            && $this->last_sample_at->diffInSeconds(now(), true) <= (int) config('power-metrics.stale_after_seconds', 300);
    }

    /** direct | plug | estimated | offline */
    public function mode(): string
    {
        if (!$this->isOnline()) {
            return self::MODE_OFFLINE;
        }

        return match ($this->last_method) {
            self::METHOD_DIRECT => self::MODE_DIRECT,
            self::METHOD_PLUG => self::MODE_PLUG,
            default => $this->last_estimated ? self::MODE_ESTIMATED : self::MODE_DIRECT,
        };
    }

    /** Kurzbeschreibung der eingerichteten Kette, z. B. "Push → Shelly Gen2 → Schätzung" */
    public function chainLabel(): string
    {
        $steps = [];

        if ($this->hasDirect()) {
            $steps[] = self::directMethods()[$this->direct_method] ?? $this->direct_method;
        }

        if ($this->hasPlug()) {
            $steps[] = self::plugTypes()[$this->plug_type] ?? $this->plug_type;
        }

        if ($this->canEstimate()) {
            $steps[] = trans('power-metrics::strings.mode.estimated');
        }

        return $steps ? implode(' → ', $steps) : '–';
    }
}
