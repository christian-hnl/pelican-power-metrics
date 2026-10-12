<?php

namespace ChristianHnl\PowerMetrics\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $power_source_id
 * @property float $watts
 * @property CarbonInterface $recorded_at
 */
class PowerSample extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'power_source_id',
        'watts',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'watts' => 'float',
            'recorded_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PowerSource::class, 'power_source_id');
    }
}
