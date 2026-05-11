<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model Route
 *
 * Koridor TransJakarta (K1, K2, dst).
 * Satu koridor punya banyak halte (stops) dan banyak bus (vehicles).
 *
 * @property int         $id
 * @property string      $code         Kode koridor: K1, K2, K3, ...
 * @property string      $name         Nama lengkap koridor
 * @property bool        $is_active
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
class Route extends Model
{
    use SoftDeletes;

    protected $table = 'routes';

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ──────────────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────────────

    public function stops(): HasMany
    {
        return $this->hasMany(Stop::class)->orderBy('sequence');
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function activeVehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class)->where('is_active', true);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Accessors
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Rata-rata occupancy ratio semua bus aktif di koridor ini.
     * Berguna untuk dasbor operator (ringkasan per koridor).
     */
    public function getAvgOccupancyRatioAttribute(): ?float
    {
        $vehicles = $this->activeVehicles()->with('latestDensityLog')->get();

        $ratios = $vehicles
            ->filter(fn ($v) => $v->latestDensityLog !== null)
            ->map(fn ($v) => $v->current_occupancy_ratio)
            ->filter();

        return $ratios->isNotEmpty()
            ? round($ratios->avg(), 4)
            : null;
    }
}