<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\DensityLog;

/**
 * Model Forecast
 *
 * Menyimpan hasil prediksi kepadatan bus untuk 5, 10, dan 15 menit ke depan.
 * Di-upsert setiap kali ForecastingService::forecast() dipanggil.
 * Satu baris per kombinasi (vehicle_id, minutes_ahead) — selalu data terkini.
 *
 * @property int         $id
 * @property int         $vehicle_id
 * @property int         $minutes_ahead          5 | 10 | 15
 * @property int         $predicted_count        Prediksi jumlah penumpang
 * @property float       $predicted_occupancy_ratio
 * @property string      $predicted_occupancy_level  low | medium | high | overcrowded
 * @property \Carbon\Carbon $predicted_for        Waktu yang diprediksi
 * @property string      $model_version          moving_avg_v1
 * @property int|null    $based_on_log_id        DensityLog terakhir yang dipakai
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Forecast extends Model
{
    protected $table = 'forecasts';

    protected $fillable = [
        'vehicle_id',
        'minutes_ahead',
        'predicted_count',
        'predicted_occupancy_ratio',
        'predicted_occupancy_level',
        'predicted_for',
        'model_version',
        'based_on_log_id',
    ];

    protected $casts = [
        'minutes_ahead'             => 'integer',
        'predicted_count'           => 'integer',
        'predicted_occupancy_ratio' => 'float',
        'predicted_for'             => 'datetime',
    ];

    // ──────────────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────────────

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function basedOnLog(): BelongsTo
    {
        return $this->belongsTo(DensityLog::class, 'based_on_log_id');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Filter berdasarkan vehicle.
     */
    public function scopeForVehicle($query, int $vehicleId)
    {
        return $query->where('vehicle_id', $vehicleId);
    }

    /**
     * Urutkan dari horizon terdekat ke terjauh.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('minutes_ahead');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Accessors
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Warna marker untuk frontend (Leaflet / Chart.js).
     */
    public function getMarkerColorAttribute(): string
    {
        return match ($this->predicted_occupancy_level) {
            'low'         => 'green',
            'medium'      => 'yellow',
            'high'        => 'red',
            'overcrowded' => 'darkred',
            default       => 'gray',
        };
    }
}