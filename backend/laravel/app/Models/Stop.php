<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Route;

/**
 * Model Stop
 *
 * Halte TransJakarta dalam satu koridor.
 * Urutan halte ditentukan oleh kolom sequence.
 *
 * @property int         $id
 * @property int         $route_id
 * @property string      $name        Nama halte
 * @property int         $sequence    Urutan halte dalam koridor (1, 2, 3, ...)
 * @property float       $latitude
 * @property float       $longitude
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
class Stop extends Model
{
    use SoftDeletes;

    protected $table = 'stops';

    protected $fillable = [
        'route_id',
        'name',
        'sequence',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'sequence'  => 'integer',
        'latitude'  => 'float',
        'longitude' => 'float',
    ];

    // ──────────────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────────────

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Urutkan berdasarkan sequence dalam koridor.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sequence');
    }

    public function scopeOnRoute($query, int $routeId)
    {
        return $query->where('route_id', $routeId);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Accessors
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Koordinat sebagai array [lat, lng] — siap di-feed ke Leaflet.js.
     */
    public function getCoordinatesAttribute(): array
    {
        return [$this->latitude, $this->longitude];
    }
}