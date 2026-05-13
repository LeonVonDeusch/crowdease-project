<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Models\Stop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * StopController (Admin)
 *
 * GET    /api/v1/admin/routes/{route}/stops          → list halte dalam koridor
 * POST   /api/v1/admin/routes/{route}/stops          → tambah halte
 * PUT    /api/v1/admin/routes/{route}/stops/{stop}   → update halte
 * DELETE /api/v1/admin/routes/{route}/stops/{stop}   → hapus halte
 */
class StopController extends Controller
{
    public function index(Route $route): JsonResponse
    {
        $stops = $route->stops()
            ->ordered()
            ->get()
            ->map(fn (Stop $s) => $this->formatStop($s));

        return response()->json(['data' => $stops]);
    }

    public function store(Request $request, Route $route): JsonResponse
    {
        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'sequence'  => ['required', 'integer', 'min:1'],
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        // Pastikan sequence unik dalam koridor ini
        $exists = $route->stops()->where('sequence', $validated['sequence'])->exists();
        if ($exists) {
            return response()->json([
                'message' => "Urutan halte {$validated['sequence']} sudah ada di koridor ini.",
            ], 422);
        }

        $stop = $route->stops()->create($validated);

        return response()->json([
            'message' => 'Halte berhasil ditambahkan.',
            'data'    => $this->formatStop($stop),
        ], 201);
    }

    public function update(Request $request, Route $route, Stop $stop): JsonResponse
    {
        // Pastikan stop ini milik route yang benar
        if ($stop->route_id !== $route->id) {
            return response()->json(['message' => 'Halte tidak ditemukan di koridor ini.'], 404);
        }

        $validated = $request->validate([
            'name'      => ['sometimes', 'string', 'max:255'],
            'sequence'  => ['sometimes', 'integer', 'min:1'],
            'latitude'  => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
        ]);

        if (isset($validated['sequence']) && $validated['sequence'] !== $stop->sequence) {
            $exists = $route->stops()
                ->where('sequence', $validated['sequence'])
                ->where('id', '!=', $stop->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => "Urutan halte {$validated['sequence']} sudah dipakai.",
                ], 422);
            }
        }

        $stop->update($validated);

        return response()->json([
            'message' => 'Halte berhasil diperbarui.',
            'data'    => $this->formatStop($stop->fresh()),
        ]);
    }

    public function destroy(Route $route, Stop $stop): JsonResponse
    {
        if ($stop->route_id !== $route->id) {
            return response()->json(['message' => 'Halte tidak ditemukan di koridor ini.'], 404);
        }

        $stop->delete();

        return response()->json(['message' => 'Halte berhasil dihapus.']);
    }

    private function formatStop(Stop $stop): array
    {
        return [
            'id'          => $stop->id,
            'name'        => $stop->name,
            'sequence'    => $stop->sequence,
            'latitude'    => $stop->latitude,
            'longitude'   => $stop->longitude,
            'coordinates' => $stop->coordinates,
        ];
    }
}