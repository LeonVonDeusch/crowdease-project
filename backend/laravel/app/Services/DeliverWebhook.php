<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * DeliverWebhook
 *
 * Queued job yang mengirim satu webhook delivery.
 * Retry policy (sesuai API_CONTRACT.md section 4.3):
 *
 *   Attempt 1 → langsung
 *   Attempt 2 → +30 detik
 *   Attempt 3 → +5 menit
 *   Attempt 4 → +30 menit
 *   Attempt 5 → +6 jam
 *
 * Setelah 5 attempt gagal, delivery ditandai 'permanently_failed'.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Jumlah maksimum percobaan (termasuk percobaan pertama).
     */
    public int $tries = 5;

    /**
     * Timeout per attempt dalam detik.
     */
    public int $timeout = 30;

    /**
     * Delay antar attempt (detik), sesuai retry policy.
     * Index 0 = setelah attempt ke-1 gagal, dst.
     */
    private array $retryDelays = [
        30,       // attempt 2: +30 detik
        300,      // attempt 3: +5 menit
        1800,     // attempt 4: +30 menit
        21600,    // attempt 5: +6 jam
    ];

    public function __construct(
        private readonly int $deliveryId
    ) {}

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $delivery = WebhookDelivery::findOrFail($this->deliveryId);

        // Skip jika sudah delivered (misal job duplikat)
        if ($delivery->status === 'delivered') {
            return;
        }

        $result = $dispatcher->send($delivery);

        if (! $result['success']) {
            // Masih ada attempt tersisa → re-throw agar Laravel re-queue
            if ($this->attempts() < $this->tries) {
                $this->release($this->getDelay());
                return;
            }

            // Semua attempt habis
            $delivery->update(['status' => 'permanently_failed']);
        }
    }

    /**
     * Hitung delay untuk attempt saat ini.
     * attempts() mengembalikan jumlah attempt yang sudah dilakukan.
     */
    private function getDelay(): int
    {
        $index = $this->attempts() - 1; // 0-based
        return $this->retryDelays[$index] ?? 21600;
    }

    /**
     * Dipanggil saat job benar-benar gagal semua attempt.
     */
    public function failed(\Throwable $exception): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);

        if ($delivery) {
            $delivery->update([
                'status'        => 'permanently_failed',
                'response_body' => $exception->getMessage(),
            ]);
        }
    }
}