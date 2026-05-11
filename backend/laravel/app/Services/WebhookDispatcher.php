<?php

namespace App\Services;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Jobs\DeliverWebhook;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * WebhookDispatcher
 *
 * Mengurus pengiriman event outbound ke semua webhook yang terdaftar.
 * Mendukung target generik (JSON POST), Telegram Bot API, dan Discord Webhook.
 *
 * Dipanggil dari Listener\DispatchWebhooks setelah event terjadi.
 *
 * Pola keamanan: setiap payload ditandatangani dengan HMAC SHA-256
 * menggunakan secret yang di-generate saat webhook didaftarkan.
 */
class WebhookDispatcher
{
    /**
     * Event yang valid (sesuai API_CONTRACT.md section 3.5).
     */
    public const VALID_EVENTS = [
        'density.recorded',
        'density.high_threshold_crossed',
        'density.low_threshold_recovered',
        'vehicle.created',
        'vehicle.updated',
    ];

    /**
     * Dispatch event ke semua webhook aktif yang subscribe ke event ini.
     *
     * @param  string  $eventName   Salah satu dari VALID_EVENTS
     * @param  array   $data        Data payload event
     */
    public function dispatch(string $eventName, array $data): void
    {
        $webhooks = Webhook::where('is_active', true)
            ->whereJsonContains('events', $eventName)
            ->get();

        foreach ($webhooks as $webhook) {
            $delivery = $this->createDelivery($webhook, $eventName, $data);

            // Dispatch ke queue — retry policy ada di DeliverWebhook Job
            DeliverWebhook::dispatch($delivery->id);
        }
    }

    /**
     * Kirim langsung (synchronous) — dipakai untuk endpoint test ping
     * POST /admin/webhooks/{id}/test
     *
     * @param  Webhook  $webhook
     * @param  string   $eventName
     * @param  array    $data
     * @return array    ['success' => bool, 'status_code' => int, 'response_body' => string]
     */
    public function dispatchNow(Webhook $webhook, string $eventName, array $data): array
    {
        $delivery = $this->createDelivery($webhook, $eventName, $data);

        return $this->send($delivery);
    }

    /**
     * Lakukan HTTP request ke URL webhook dan update record delivery.
     * Dipanggil dari DeliverWebhook Job.
     *
     * @param  WebhookDelivery  $delivery
     * @return array
     */
    public function send(WebhookDelivery $delivery): array
    {
        // Pastikan relasi di-load sebagai Eloquent model, bukan stdClass
        $delivery->loadMissing('webhook');
        $webhook = $delivery->webhook;

        if (! $webhook instanceof Webhook) {
            return [
                'success'       => false,
                'status_code'   => 0,
                'response_body' => 'Webhook record not found.',
            ];
        }

        $payload = $delivery->payload;

        $payloadJson = json_encode($payload);
        $timestamp   = time();
        $deliveryId  = $delivery->delivery_id;

        $signature = $this->generateSignature($payloadJson, $webhook->secret, $timestamp);

        $headers = [
            'Content-Type'              => 'application/json',
            'User-Agent'                => 'CrowdEase-Webhook/1.0',
            'X-CrowdEase-Event'         => $payload['event'],
            'X-CrowdEase-Delivery-Id'   => $deliveryId,
            'X-CrowdEase-Signature'     => $signature,
            'X-CrowdEase-Timestamp'     => (string) $timestamp,
        ];

        try {
            // Deteksi target dan format payload jika perlu
            $body = $this->formatPayload($webhook->url, $payload, $payloadJson);

            $response = Http::timeout(10)
                ->withHeaders($headers)
                ->post($webhook->url, json_decode($body, true));

            $statusCode   = $response->status();
            $responseBody = substr($response->body(), 0, 1000); // limit log
            $success      = $response->successful(); // 2xx

            $delivery->update([
                'status'        => $success ? 'delivered' : 'failed',
                'response_code' => $statusCode,
                'response_body' => $responseBody,
                'delivered_at'  => $success ? Carbon::now() : null,
                'attempt'       => $delivery->attempt + 1,
            ]);

            return [
                'success'       => $success,
                'status_code'   => $statusCode,
                'response_body' => $responseBody,
            ];
        } catch (\Throwable $e) {
            $delivery->update([
                'status'        => 'failed',
                'response_code' => null,
                'response_body' => $e->getMessage(),
                'attempt'       => $delivery->attempt + 1,
            ]);

            return [
                'success'       => false,
                'status_code'   => 0,
                'response_body' => $e->getMessage(),
            ];
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Telegram & Discord formatter
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Deteksi tipe URL dan transform payload ke format yang sesuai.
     *
     * - Telegram Bot API : https://api.telegram.org/bot<TOKEN>/sendMessage
     * - Discord Webhook  : https://discord.com/api/webhooks/...
     * - Generic          : JSON POST apa adanya
     */
    private function formatPayload(string $url, array $payload, string $defaultJson): string
    {
        if (str_contains($url, 'api.telegram.org')) {
            return json_encode($this->formatForTelegram($payload));
        }

        if (str_contains($url, 'discord.com/api/webhooks')) {
            return json_encode($this->formatForDiscord($payload));
        }

        return $defaultJson;
    }

    /**
     * Format pesan untuk Telegram Bot API (sendMessage).
     *
     * URL format: https://api.telegram.org/bot<TOKEN>/sendMessage?chat_id=<CHAT_ID>
     * chat_id bisa diambil dari query param atau disimpan di webhook metadata.
     */
    private function formatForTelegram(array $payload): array
    {
        $event   = $payload['event'] ?? 'unknown';
        $density = $payload['data']['density'] ?? [];
        $vehicle = $payload['data']['vehicle'] ?? [];

        $emoji = match ($density['occupancy_level'] ?? '') {
            'high'        => '🔴',
            'medium'      => '🟡',
            'low'         => '🟢',
            'overcrowded' => '⛔',
            default       => '⚪',
        };

        $ratio   = isset($density['occupancy_ratio'])
            ? round($density['occupancy_ratio'] * 100) . '%'
            : '-';

        $text = implode("\n", [
            "{$emoji} *CrowdEase Alert*",
            "Event: `{$event}`",
            "Bus: `{$vehicle['plate_number']}` — Koridor {$vehicle['route_code']}",
            "Penumpang: {$density['passenger_count']}/{$density['capacity']} ({$ratio})",
            "Status: *" . strtoupper($density['occupancy_level'] ?? '-') . "*",
            "Waktu: {$density['recorded_at']}",
        ]);

        return [
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ];
    }

    /**
     * Format pesan untuk Discord Webhook (embed).
     *
     * URL format: https://discord.com/api/webhooks/<ID>/<TOKEN>
     */
    private function formatForDiscord(array $payload): array
    {
        $event   = $payload['event'] ?? 'unknown';
        $density = $payload['data']['density'] ?? [];
        $vehicle = $payload['data']['vehicle'] ?? [];

        $level = $density['occupancy_level'] ?? 'unknown';
        $color = match ($level) {
            'high'        => 0xFF4444, // merah
            'medium'      => 0xFFAA00, // kuning
            'low'         => 0x22CC66, // hijau
            'overcrowded' => 0x880000, // merah tua
            default       => 0xAAAAAA,
        };

        $ratio = isset($density['occupancy_ratio'])
            ? round($density['occupancy_ratio'] * 100) . '%'
            : '-';

        return [
            'username'   => 'CrowdEase Bot',
            'embeds'     => [
                [
                    'title'       => '🚌 CrowdEase — ' . $event,
                    'color'       => $color,
                    'fields'      => [
                        [
                            'name'   => 'Bus',
                            'value'  => "`{$vehicle['plate_number']}` — Koridor {$vehicle['route_code']}",
                            'inline' => false,
                        ],
                        [
                            'name'   => 'Penumpang',
                            'value'  => "{$density['passenger_count']} / {$density['capacity']} ({$ratio})",
                            'inline' => true,
                        ],
                        [
                            'name'   => 'Status',
                            'value'  => strtoupper($level),
                            'inline' => true,
                        ],
                        [
                            'name'   => 'Waktu',
                            'value'  => $density['recorded_at'] ?? '-',
                            'inline' => false,
                        ],
                    ],
                    'footer'      => ['text' => 'CrowdEase • TIS TI-D Kelompok 7'],
                    'timestamp'   => Carbon::now()->toIso8601String(),
                ],
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Buat record WebhookDelivery sebelum dikirim.
     */
    private function createDelivery(Webhook $webhook, string $eventName, array $data): WebhookDelivery
    {
        $deliveredAt = Carbon::now('Asia/Jakarta');

        $payload = [
            'event'        => $eventName,
            'delivered_at' => $deliveredAt->toIso8601String(),
            'data'         => $data,
        ];

        return WebhookDelivery::create([
            'webhook_id'  => $webhook->id,
            'delivery_id' => Str::uuid()->toString(),
            'event'       => $eventName,
            'payload'     => $payload,
            'status'      => 'pending',
            'attempt'     => 0,
        ]);
    }

    /**
     * Generate HMAC SHA-256 signature.
     *
     * Format: sha256=<hex>
     * Consumer verifies: expected = "sha256=" + hmac_sha256(payload_body, secret)
     */
    private function generateSignature(string $payloadJson, string $secret, int $timestamp): string
    {
        // Sertakan timestamp agar signature tidak bisa di-replay
        $signedPayload = $timestamp . '.' . $payloadJson;

        return 'sha256=' . hash_hmac('sha256', $signedPayload, $secret);
    }
}