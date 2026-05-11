<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;


/**
 * Model Vehicle
 *
 * Merepresentasikan satu unit bus TransJakarta yang beroperasi di koridor tertentu.
 *
 * @property int         $id
 * @property string      $plate_number       Nomor polisi bus (unik)
 * @property int         $route_id           Koridor yang dilayani
 * @property int         $capacity           Kapasitas penumpang normal
 * @property bool        $is_active          Apakah bus sedang beroperasi
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
class Vehicle extends Model
{
    use SoftDeletes;

    protected $table = 'vehicles';

    protected $fillable = [
        'plate_number',
        'route_id',
        'capacity',
        'is_active',
    ];

    protected $casts = [
        'capacity'  => 'integer',
        'is_active' => 'boolean',
    ];

    // ──────────────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────────────

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function densityLogs(): HasMany
    {
        return $this->hasMany(DensityLog::class);
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(Forecast::class);
    }

    /**
     * Density log terbaru (untuk endpoint current density).
     */
    public function latestDensityLog(): HasOne
    {
        return $this->hasOne(DensityLog::class)->latestOfMany('recorded_at');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOnRoute($query, int $routeId)
    {
        return $query->where('route_id', $routeId);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Accessors
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Occupancy ratio terkini berdasarkan latestDensityLog.
     * Mengembalikan null jika belum ada data sensor.
     */
    public function getCurrentOccupancyRatioAttribute(): ?float
    {
        $log = $this->latestDensityLog;

        if (! $log || $log->capacity_at_time === 0) {
            return null;
        }

        return round($log->passenger_count / $log->capacity_at_time, 4);
    }

    /**
     * Occupancy level terkini: low | medium | high | overcrowded | null.
     */
    public function getCurrentOccupancyLevelAttribute(): ?string
    {
        return $this->latestDensityLog?->occupancy_level;
    }

    /**
     * Warna marker Leaflet berdasarkan kondisi terkini.
     */
    public function getMarkerColorAttribute(): string
    {
        return match ($this->current_occupancy_level) {
            'low'         => 'green',
            'medium'      => 'yellow',
            'high'        => 'red',
            'overcrowded' => 'darkred',
            default       => 'gray',
        };
    }
}