<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model DensityLog
 *
 * Catatan kepadatan bus dari sensor IoT.
 * Setiap POST /sensors/readings dari simulator menghasilkan satu record di sini.
 *
 * @property int         $id
 * @property int         $vehicle_id
 * @property int         $passenger_count     Jumlah penumpang saat reading
 * @property int         $capacity_at_time    Kapasitas bus saat reading
 * @property float       $occupancy_ratio     passenger_count / capacity_at_time
 * @property string      $occupancy_level     low | medium | high | overcrowded
 * @property \Carbon\Carbon $recorded_at      Waktu dari sensor (bisa beda dengan created_at)
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class DensityLog extends Model
{
    protected $table = 'density_logs';

    protected $fillable = [
        'vehicle_id',
        'passenger_count',
        'capacity_at_time',
        'occupancy_ratio',
        'occupancy_level',
        'recorded_at',
    ];

    protected $casts = [
        'passenger_count'  => 'integer',
        'capacity_at_time' => 'integer',
        'occupancy_ratio'  => 'float',
        'recorded_at'      => 'datetime',
    ];

    // ──────────────────────────────────────────────────────────────────────
    // Boot — hitung occupancy_ratio & level otomatis sebelum disimpan
    // ──────────────────────────────────────────────────────────────────────

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (DensityLog $log) {
            if ($log->capacity_at_time > 0) {
                $log->occupancy_ratio = round(
                    $log->passenger_count / $log->capacity_at_time,
                    4
                );
            }

            $log->occupancy_level = static::resolveLevel($log->occupancy_ratio ?? 0);
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────────────

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Forecast yang dibuat berdasarkan log ini.
     */
    public function forecasts(): HasMany
    {
        return $this->hasMany(Forecast::class, 'based_on_log_id');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────────────

    public function scopeForVehicle($query, int $vehicleId)
    {
        return $query->where('vehicle_id', $vehicleId);
    }

    public function scopeRecent($query, int $limit = 5)
    {
        return $query->orderByDesc('recorded_at')->limit($limit);
    }

    /**
     * Filter berdasarkan level kepadatan.
     */
    public function scopeAtLevel($query, string $level)
    {
        return $query->where('occupancy_level', $level);
    }

    /**
     * Filter rentang waktu — untuk endpoint analytics/hourly.
     */
    public function scopeInDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('recorded_at', [$from, $to]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Accessors
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Selisih detik antara recorded_at dan sekarang.
     * Dipakai frontend untuk indikator "data lama" (> 60 detik).
     */
    public function getAgeSecondsAttribute(): int
    {
        return (int) abs(now()->diffInSeconds($this->recorded_at));
    }

    /**
     * Warna marker untuk Leaflet / Chart.js.
     */
    public function getMarkerColorAttribute(): string
    {
        return match ($this->occupancy_level) {
            'low'         => 'green',
            'medium'      => 'yellow',
            'high'        => 'red',
            'overcrowded' => 'darkred',
            default       => 'gray',
        };
    }

    // ──────────────────────────────────────────────────────────────────────
    // Static helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Resolusi occupancy level dari ratio.
     * Dipakai di boot() dan bisa dipanggil dari ForecastingService.
     */
    public static function resolveLevel(float $ratio): string
    {
        if ($ratio > 1.0)  return 'overcrowded';
        if ($ratio >= 0.8) return 'high';
        if ($ratio >= 0.5) return 'medium';

        return 'low';
    }
}