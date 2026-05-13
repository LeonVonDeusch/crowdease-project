<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ApiKeyController (Admin)
 *
 * GET    /api/v1/admin/api-keys          → list semua API key (tanpa plain key)
 * POST   /api/v1/admin/api-keys          → generate key baru (plain key dikembalikan SEKALI)
 * DELETE /api/v1/admin/api-keys/{id}     → revoke / nonaktifkan key
 */
class ApiKeyController extends Controller
{
    public function index(): JsonResponse
    {
        $keys = ApiKey::with('creator:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ApiKey $k) => $this->formatKey($k));

        return response()->json(['data' => $keys]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        ['model' => $apiKey, 'plain_key' => $plainKey] = ApiKey::generate(
            $request->input('name'),
            $request->user()->id,
        );

        $apiKey->load('creator:id,name');

        return response()->json([
            'message' => 'API key berhasil dibuat. Simpan key ini — tidak akan ditampilkan lagi.',
            'data'    => array_merge($this->formatKey($apiKey), [
                'key' => $plainKey, // hanya muncul sekali saat create
            ]),
        ], 201);
    }

    public function destroy(ApiKey $apiKey): JsonResponse
    {
        $apiKey->update(['is_active' => false]);

        return response()->json([
            'message' => 'API key berhasil dinonaktifkan.',
        ]);
    }

    private function formatKey(ApiKey $apiKey): array
    {
        return [
            'id'           => $apiKey->id,
            'name'         => $apiKey->name,
            'key_prefix'   => $apiKey->key_prefix . '...',
            'is_active'    => $apiKey->is_active,
            'last_used_at' => $apiKey->last_used_at?->toIso8601String(),
            'created_by'   => $apiKey->creator?->name,
            'created_at'   => $apiKey->created_at->toIso8601String(),
        ];
    }
}