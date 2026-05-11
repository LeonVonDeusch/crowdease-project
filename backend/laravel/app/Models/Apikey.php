<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Model ApiKey
 *
 * API key untuk autentikasi IoT Simulator via header X-API-Key.
 * Key asli hanya muncul sekali saat dibuat — yang disimpan di DB adalah hash-nya.
 *
 * @property int         $id
 * @property string      $name           Label key (mis: "Simulator Lab A")
 * @property string      $key_hash       Hash bcrypt dari key asli
 * @property string      $key_prefix     8 char pertama key asli (untuk identifikasi di list)
 * @property int         $created_by     User operator yang membuat
 * @property bool        $is_active
 * @property \Carbon\Carbon|null $last_used_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ApiKey extends Model
{
    protected $table = 'api_keys';

    protected $fillable = [
        'name',
        'key_hash',
        'key_prefix',
        'created_by',
        'is_active',
        'last_used_at',
    ];

    protected $hidden = [
        'key_hash',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'last_used_at' => 'datetime',
    ];

    // ──────────────────────────────────────────────────────────────────────
    // Static factory
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Generate key baru, buat record, kembalikan plain key (hanya sekali).
     *
     * @param  string  $name
     * @param  int     $createdBy  User ID operator
     * @return array   ['model' => ApiKey, 'plain_key' => string]
     */
    public static function generate(string $name, int $createdBy): array
    {
        $plainKey = 'ce_iot_' . Str::random(32);

        $model = static::create([
            'name'       => $name,
            'key_hash'   => Hash::make($plainKey),
            'key_prefix' => substr($plainKey, 0, 14), // "ce_iot_" + 7 char
            'created_by' => $createdBy,
            'is_active'  => true,
        ]);

        return [
            'model'     => $model,
            'plain_key' => $plainKey,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Verifikasi apakah plain key cocok dengan hash yang tersimpan.
     * Dipanggil dari middleware ApiKeyAuth.
     */
    public function verify(string $plainKey): bool
    {
        return $this->is_active && Hash::check($plainKey, $this->key_hash);
    }

    /**
     * Catat waktu terakhir key ini dipakai.
     */
    public function touchLastUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}