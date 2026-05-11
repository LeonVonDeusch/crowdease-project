<?php

namespace App\Services;

use App\Models\DensityLog;
use App\Models\Forecast;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * ForecastingService
 *
 * Memprediksi kepadatan bus TransJakarta untuk 5, 10, dan 15 menit ke depan
 * menggunakan algoritma Moving Average atas N data terakhir.
 *
 * Model: moving_avg_v1
 * Dipanggil setiap kali sensor reading masuk (dari SensorReadingController).
 */
class ForecastingService
{
    /**
     * Jumlah data historis yang dipakai untuk kalkulasi moving average.
     */
    private const WINDOW_SIZE = 5;

    /**
     * Menit ke depan yang diprediksi.
     */
    private const FORECAST_HORIZONS = [5, 10, 15];

    /**
     * Batas ratio untuk menentukan occupancy_level.
     */
    private const THRESHOLD_LOW    = 0.5;
    private const THRESHOLD_HIGH   = 0.8;

    /**
     * Entry point utama: hitung prediksi dan simpan ke tabel forecasts.
     *
     * @param  int  $vehicleId
     * @return array  Array of forecast data yang baru disimpan
     */
    public function forecast(int $vehicleId): array
    {
        $vehicle = Vehicle::findOrFail($vehicleId);

        // Ambil N data terbaru untuk vehicle ini
        $recentLogs = $this->getRecentLogs($vehicleId);

        if ($recentLogs->isEmpty()) {
            return [];
        }

        $currentLog = $recentLogs->first();
        $capacity   = $currentLog->capacity_at_time;

        // Hitung moving average dari passenger_count
        $avgPassengerCount = $this->movingAverage($recentLogs);

        // Hitung trend (delta rata-rata antar reading)
        $trend = $this->calculateTrend($recentLogs);

        $forecasts = [];

        foreach (self::FORECAST_HORIZONS as $minutesAhead) {
            // Prediksi sederhana: avg + (trend * horizon)
            $predictedCount = (int) round($avgPassengerCount + ($trend * $minutesAhead));

            // Clamp agar tidak negatif atau melebihi kapasitas * 1.2 (overcrowded)
            $predictedCount = max(0, min($predictedCount, (int) ($capacity * 1.2)));

            $predictedRatio = $capacity > 0
                ? round($predictedCount / $capacity, 4)
                : 0;

            $predictedLevel = $this->resolveOccupancyLevel($predictedRatio);

            $predictedFor = Carbon::now('Asia/Jakarta')
                ->addMinutes($minutesAhead);

            // Upsert ke tabel forecasts (satu baris per vehicle per horizon)
            $forecast = Forecast::updateOrCreate(
                [
                    'vehicle_id'     => $vehicleId,
                    'minutes_ahead'  => $minutesAhead,
                ],
                [
                    'predicted_count'           => $predictedCount,
                    'predicted_occupancy_ratio'  => $predictedRatio,
                    'predicted_occupancy_level'  => $predictedLevel,
                    'predicted_for'             => $predictedFor,
                    'model_version'             => 'moving_avg_v1',
                    'based_on_log_id'           => $currentLog->id,
                ]
            );

            $forecasts[] = [
                'predicted_for'             => $predictedFor->toIso8601String(),
                'minutes_ahead'             => $minutesAhead,
                'predicted_count'           => $predictedCount,
                'predicted_occupancy_level' => $predictedLevel,
            ];
        }

        return $forecasts;
    }

    /**
     * Ambil hasil forecast terkini untuk satu vehicle.
     * Dipakai oleh PublicController untuk endpoint GET /vehicles/{id}/density/forecast
     *
     * @param  int  $vehicleId
     * @return array
     */
    public function getLatestForecasts(int $vehicleId): array
    {
        $forecasts = Forecast::where('vehicle_id', $vehicleId)
            ->orderBy('minutes_ahead')
            ->get();

        if ($forecasts->isEmpty()) {
            return [];
        }

        return $forecasts->map(fn ($f) => [
            'predicted_for'             => Carbon::parse($f->predicted_for)
                ->timezone('Asia/Jakarta')
                ->toIso8601String(),
            'minutes_ahead'             => $f->minutes_ahead,
            'predicted_count'           => $f->predicted_count,
            'predicted_occupancy_level' => $f->predicted_occupancy_level,
        ])->toArray();
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Ambil WINDOW_SIZE density log terbaru untuk satu vehicle.
     */
    private function getRecentLogs(int $vehicleId): Collection
    {
        return DensityLog::where('vehicle_id', $vehicleId)
            ->orderByDesc('recorded_at')
            ->limit(self::WINDOW_SIZE)
            ->get();
    }

    /**
     * Hitung simple moving average dari passenger_count.
     */
    private function movingAverage(Collection $logs): float
    {
        return $logs->avg('passenger_count') ?? 0.0;
    }

    /**
     * Hitung trend (rata-rata delta per interval antar reading).
     *
     * Positif = jumlah penumpang cenderung naik
     * Negatif = cenderung turun
     */
    private function calculateTrend(Collection $logs): float
    {
        if ($logs->count() < 2) {
            return 0.0;
        }

        // Logs sudah diurutkan DESC (terbaru duluan), balik agar kronologis
        $ordered = $logs->reverse()->values();

        $deltas = [];
        for ($i = 1; $i < $ordered->count(); $i++) {
            $deltas[] = $ordered[$i]->passenger_count - $ordered[$i - 1]->passenger_count;
        }

        return count($deltas) > 0
            ? array_sum($deltas) / count($deltas)
            : 0.0;
    }

    /**
     * Tentukan occupancy_level berdasarkan ratio.
     *
     * < 0.5          → low
     * 0.5 – 0.79     → medium
     * 0.8 – 1.0      → high
     * > 1.0          → overcrowded
     */
    public function resolveOccupancyLevel(float $ratio): string
    {
        if ($ratio > 1.0) {
            return 'overcrowded';
        }

        if ($ratio >= self::THRESHOLD_HIGH) {
            return 'high';
        }

        if ($ratio >= self::THRESHOLD_LOW) {
            return 'medium';
        }

        return 'low';
    }
}