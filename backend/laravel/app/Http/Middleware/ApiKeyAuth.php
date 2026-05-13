<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ApiKeyAuth Middleware
 *
 * Memvalidasi X-API-Key header yang dikirim oleh IoT Simulator.
 *
 * Cara kerja:
 *  1. Operator generate API key via dasbor admin (POST /admin/api-keys)
 *  2. ApiKey::generate() menyimpan hash-nya ke DB dan mengembalikan plain key sekali
 *  3. Plain key di-paste ke konfigurasi IoT Simulator
 *  4. Setiap request dari simulator menyertakan: X-API-Key: ce_iot_xxxxx
 *  5. Middleware ini ambil key dari header, cari prefix-nya di DB, lalu verify hash-nya
 *
 * Dipasang di routes/api/v1/iot.php
 */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainKey = $request->header('X-API-Key');

        // Header tidak ada atau kosong
        if (empty($plainKey)) {
            return response()->json([
                'message' => 'API key missing. Provide X-API-Key header.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Cari kandidat key berdasarkan prefix (14 char pertama)
        // Tujuannya agar tidak harus hash semua key aktif di DB — cukup filter dulu by prefix
        $prefix = substr($plainKey, 0, 14);

        $apiKey = ApiKey::where('is_active', true)
            ->where('key_prefix', $prefix)
            ->first();

        // Prefix tidak ditemukan atau hash tidak cocok
        if (! $apiKey || ! $apiKey->verify($plainKey)) {
            return response()->json([
                'message' => 'Invalid or inactive API key.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Catat waktu terakhir dipakai (non-blocking)
        $apiKey->touchLastUsed();

        // Inject ke request agar controller bisa akses jika perlu
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }
}