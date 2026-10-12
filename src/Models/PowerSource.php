<?php

namespace ChristianHnl\PowerMetrics\Models;

use Carbon\CarbonInterface;
use ChristianHnl\PowerMetrics\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
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
 * @property ?float $last_watts
 * @property ?CarbonInterface $last_sample_at
 * @property ?string $last_error
 */
class PowerSource extends Model
{
    public const TYPE_PUSH = 'push';

    public const TYPE_HTTP_JSON = 'http_json';

    protected $fillable = [
        'name',
        'type',
        'enabled',
        'token',
        'url',
        'json_path',
        'auth_header',
        'multiplier',
    ];

    protected $hidden = [
        'token',
        'auth_header',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'bool',
            'multiplier' => 'float',
            'last_watts' => 'float',
            'last_sample_at' => UtcDateTime::class,
            'auth_header' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $source) {
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
            self::TYPE_PUSH => trans('power-metrics::strings.type_push'),
            self::TYPE_HTTP_JSON => trans('power-metrics::strings.type_http_json'),
        ];
    }

    public function samples(): HasMany
    {
        return $this->hasMany(PowerSample::class);
    }

    public function hourlyStats(): HasMany
    {
        return $this->hasMany(PowerHourlyStat::class);
    }

    public function isOnline(): bool
    {
        return $this->enabled
            && $this->last_sample_at !== null
            && $this->last_sample_at->diffInSeconds(now(), true) <= (int) config('power-metrics.stale_after_seconds', 300);
    }
}
