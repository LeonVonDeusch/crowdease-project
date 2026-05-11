<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Model Webhook
 *
 * Menyimpan URL webhook eksternal yang terdaftar oleh operator.
 * Mendukung target Telegram, Discord, Slack, dan URL generik lainnya.
 *
 * @property int         $id
 * @property string      $name           Label webhook (max 100 char)
 * @property string      $url            URL tujuan POST outbound
 * @property array       $events         Array event yang di-subscribe
 * @property string      $secret         HMAC secret (plain, hanya tampil sekali saat create)
 * @property bool        $is_active
 * @property int         $total_deliveries
 * @property int         $failed_deliveries
 * @property \Carbon\Carbon|null $last_triggered_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Webhook extends Model
{
    protected $table = 'webhooks';

    protected $fillable = [
        'name',
        'url',
        'events',
        'secret',
        'is_active',
        'total_deliveries',
        'failed_deliveries',
        'last_triggered_at',
    ];

    protected $casts = [
        'events'            => 'array',
        'is_active'         => 'boolean',
        'total_deliveries'  => 'integer',
        'failed_deliveries' => 'integer',
        'last_triggered_at' => 'datetime',
    ];

    /**
     * Field yang tidak boleh muncul di response JSON.
     * Secret hanya dikembalikan sekali saat create (di controller).
     */
    protected $hidden = [
        'secret',
    ];

    // ──────────────────────────────────────────────────────────────────────
    // Boot
    // ──────────────────────────────────────────────────────────────────────

    protected static function boot(): void
    {
        parent::boot();

        /**
         * Auto-generate secret saat webhook baru dibuat.
         * Format: whsec_<32 char random hex>
         * Disimpan plain — hashing di sini akan membuat verifikasi HMAC tidak mungkin.
         */
        static::creating(function (Webhook $webhook) {
            if (empty($webhook->secret)) {
                $webhook->secret = 'whsec_' . Str::random(32);
            }
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────────────

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Hanya webhook yang aktif.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Webhook yang subscribe ke event tertentu.
     */
    public function scopeSubscribedTo($query, string $event)
    {
        return $query->whereJsonContains('events', $event);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Cek apakah webhook subscribe ke event ini.
     */
    public function subscribesTo(string $event): bool
    {
        return in_array($event, $this->events ?? [], true);
    }

    /**
     * Deteksi tipe target berdasarkan URL.
     * Dipakai WebhookDispatcher untuk format payload yang tepat.
     */
    public function getTargetType(): string
    {
        if (str_contains($this->url, 'api.telegram.org')) {
            return 'telegram';
        }

        if (str_contains($this->url, 'discord.com/api/webhooks')) {
            return 'discord';
        }

        if (str_contains($this->url, 'hooks.slack.com')) {
            return 'slack';
        }

        return 'generic';
    }

    /**
     * Increment counter setelah delivery.
     * Dipanggil dari DeliverWebhook Job.
     */
    public function recordDelivery(bool $success): void
    {
        $this->increment('total_deliveries');

        if (! $success) {
            $this->increment('failed_deliveries');
        }

        $this->update(['last_triggered_at' => now()]);
    }
}