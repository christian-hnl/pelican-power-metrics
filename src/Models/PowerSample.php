<?php

namespace ChristianHnl\PowerMetrics\Models;

use Carbon\CarbonInterface;
use ChristianHnl\PowerMetrics\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $power_source_id
 * @property float $watts
 * @property CarbonInterface $recorded_at
 * @property bool $estimated
 */
class PowerSample extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'power_source_id',
        'watts',
        'recorded_at',
        'estimated',
    ];

    protected function casts(): array
    {
        return [
            'watts' => 'float',
            'recorded_at' => UtcDateTime::class,
            'estimated' => 'bool',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PowerSource::class, 'power_source_id');
    }
}
