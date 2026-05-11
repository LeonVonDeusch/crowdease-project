<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model WebhookDelivery
 *
 * Log setiap percobaan pengiriman webhook outbound.
 * Satu record dibuat per dispatch, di-update setiap attempt oleh DeliverWebhook Job.
 *
 * @property int         $id
 * @property int         $webhook_id
 * @property string      $delivery_id     UUID unik per pengiriman (header X-CrowdEase-Delivery-Id)
 * @property string      $event           Nama event yang dikirim
 * @property array       $payload         Full payload JSON
 * @property string      $status          pending | delivered | failed | permanently_failed
 * @property int|null    $response_code   HTTP status code dari receiver
 * @property string|null $response_body   Potongan response body (max 1000 char)
 * @property int         $attempt         Jumlah attempt yang sudah dilakukan
 * @property \Carbon\Carbon|null $delivered_at   Waktu sukses dikirim
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class WebhookDelivery extends Model
{
    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'webhook_id',
        'delivery_id',
        'event',
        'payload',
        'status',
        'response_code',
        'response_body',
        'attempt',
        'delivered_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'response_code' => 'integer',
        'attempt'      => 'integer',
        'delivered_at' => 'datetime',
    ];

    // ──────────────────────────────────────────────────────────────────────
    // Constants
    // ──────────────────────────────────────────────────────────────────────

    const STATUS_PENDING           = 'pending';
    const STATUS_DELIVERED         = 'delivered';
    const STATUS_FAILED            = 'failed';
    const STATUS_PERMANENTLY_FAILED = 'permanently_failed';

    // ──────────────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────────────

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', self::STATUS_DELIVERED);
    }

    public function scopeFailed($query)
    {
        return $query->whereIn('status', [
            self::STATUS_FAILED,
            self::STATUS_PERMANENTLY_FAILED,
        ]);
    }

    /**
     * Filter berdasarkan webhook tertentu.
     */
    public function scopeForWebhook($query, int $webhookId)
    {
        return $query->where('webhook_id', $webhookId);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Accessors
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Apakah delivery ini sukses.
     */
    public function getIsSuccessfulAttribute(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    /**
     * Apakah masih bisa di-retry.
     */
    public function getIsRetryableAttribute(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_FAILED,
        ]) && $this->attempt < 5;
    }
}