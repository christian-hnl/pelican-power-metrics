<?php

namespace ChristianHnl\PowerMetrics\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $power_source_id
 * @property CarbonInterface $hour
 * @property int $samples
 * @property float $sum_watts
 * @property float $min_watts
 * @property float $max_watts
 * @property float $energy_wh
 */
class PowerHourlyStat extends Model
{
    public $timestamps = false;

    protected $table = 'power_hourly_stats';

    protected $fillable = [
        'power_source_id',
        'hour',
        'samples',
        'sum_watts',
        'min_watts',
        'max_watts',
        'energy_wh',
    ];

    protected function casts(): array
    {
        return [
            'hour' => 'datetime',
            'samples' => 'int',
            'sum_watts' => 'float',
            'min_watts' => 'float',
            'max_watts' => 'float',
            'energy_wh' => 'float',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PowerSource::class, 'power_source_id');
    }
}
