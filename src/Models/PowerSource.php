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
 * @property int $id
 * @property string $name
 * @property string $type
 * @property bool $enabled
 * @property ?string $token
 * @property ?string $url
 * @property ?string $json_path
 * @property ?string $auth_header
 * @property float $multiplier
 * @property ?int $node_id
 * @property ?float $idle_watts
 * @property ?float $max_watts
 * @property bool $estimate_fallback
 * @property ?float $last_watts
 * @property ?CarbonInterface $last_sample_at
 * @property bool $last_estimated
 * @property ?CarbonInterface $last_measured_at
 * @property ?string $last_error
 * @property ?Node $node
 */
class PowerSource extends Model
{
    public const TYPE_PUSH = 'push';

    public const TYPE_HTTP_JSON = 'http_json';

    public const TYPE_ESTIMATE = 'estimate';

    public const MODE_MEASURED = 'measured';

    public const MODE_ESTIMATED = 'estimated';

    public const MODE_OFFLINE = 'offline';

    protected $fillable = [
        'name',
        'type',
        'enabled',
        'token',
        'url',
        'json_path',
        'auth_header',
        'multiplier',
        'node_id',
        'idle_watts',
        'max_watts',
        'estimate_fallback',
    ];

    protected $hidden = [
        'token',
        'auth_header',
    ];

    protected $attributes = [
        'estimate_fallback' => true,
        'last_estimated' => false,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'bool',
            'multiplier' => 'float',
            'node_id' => 'int',
            'idle_watts' => 'float',
            'max_watts' => 'float',
            'estimate_fallback' => 'bool',
            'last_watts' => 'float',
            'last_sample_at' => UtcDateTime::class,
            'last_estimated' => 'bool',
            'last_measured_at' => UtcDateTime::class,
            'auth_header' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // Auch beim Umstellen einer bestehenden Quelle auf Push ein Token erzeugen
        static::saving(function (self $source) {
            if ($source->type === self::TYPE_PUSH && !$source->token) {
                $source->token = self::generateToken();
            }
        });
    }

    public static function generateToken(): string
    {
        return Str::random(40);
    }

    /** @return array<string, string> */
    public static function types(): array
    {
        return [
            self::TYPE_HTTP_JSON => trans('power-metrics::strings.type_http_json'),
            self::TYPE_PUSH => trans('power-metrics::strings.type_push'),
            self::TYPE_ESTIMATE => trans('power-metrics::strings.type_estimate'),
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

    public function isMeasuring(): bool
    {
        return $this->type !== self::TYPE_ESTIMATE && self::isRecent($this->last_measured_at);
    }

    /** Darf (bzw. muss) für diese Quelle geschätzt werden? */
    public function canEstimate(): bool
    {
        return $this->node_id !== null && ($this->type === self::TYPE_ESTIMATE || $this->estimate_fallback);
    }

    public function isOnline(): bool
    {
        return $this->enabled && self::isRecent($this->last_sample_at);
    }

    /** measured | estimated | offline */
    public function mode(): string
    {
        if (!$this->isOnline()) {
            return self::MODE_OFFLINE;
        }

        return $this->last_estimated ? self::MODE_ESTIMATED : self::MODE_MEASURED;
    }

    private static function isRecent(?CarbonInterface $time): bool
    {
        return $time !== null && $time->diffInSeconds(now(), true) <= (int) config('power-metrics.stale_after_seconds', 300);
    }
}
