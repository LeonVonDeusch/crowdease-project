<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Route;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RouteController (Admin)
 *
 * GET    /api/v1/admin/routes          → list semua koridor
 * POST   /api/v1/admin/routes          → tambah koridor baru
 * GET    /api/v1/admin/routes/{id}     → detail koridor + halte + bus aktif
 * PUT    /api/v1/admin/routes/{id}     → update koridor
 * DELETE /api/v1/admin/routes/{id}     → soft delete koridor
 */
class RouteController extends Controller
{
    public function index(): JsonResponse
    {
        $routes = Route::withCount(['vehicles', 'activeVehicles', 'stops'])
            ->orderBy('code')
            ->get()
            ->map(fn (Route $r) => $this->formatRoute($r));

        return response()->json(['data' => $routes]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'      => ['required', 'string', 'max:10', 'unique:routes,code'],
            'name'      => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $route = Route::create($validated);

        return response()->json([
            'message' => 'Koridor berhasil dibuat.',
            'data'    => $this->formatRoute($route),
        ], 201);
    }

    public function show(Route $route): JsonResponse
    {
        $route->load(['stops' => fn ($q) => $q->ordered(), 'activeVehicles.latestDensityLog']);

        return response()->json([
            'data' => [
                'id'         => $route->id,
                'code'       => $route->code,
                'name'       => $route->name,
                'is_active'  => $route->is_active,
                'stops'      => $route->stops->map(fn ($s) => [
                    'id'        => $s->id,
                    'name'      => $s->name,
                    'sequence'  => $s->sequence,
                    'latitude'  => $s->latitude,
                    'longitude' => $s->longitude,
                ]),
                'vehicles'   => $route->activeVehicles->map(fn ($v) => [
                    'id'              => $v->id,
                    'plate_number'    => $v->plate_number,
                    'occupancy_level' => $v->current_occupancy_level,
                    'occupancy_ratio' => $v->current_occupancy_ratio,
                ]),
            ],
        ]);
    }

    public function update(Request $request, Route $route): JsonResponse
    {
        $validated = $request->validate([
            'code'      => ['sometimes', 'string', 'max:10', 'unique:routes,code,' . $route->id],
            'name'      => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $route->update($validated);

        return response()->json([
            'message' => 'Koridor berhasil diperbarui.',
            'data'    => $this->formatRoute($route->fresh()),
        ]);
    }

    public function destroy(Route $route): JsonResponse
    {
        // Cek apakah masih ada bus aktif di koridor ini
        if ($route->activeVehicles()->exists()) {
            return response()->json([
                'message' => 'Tidak dapat menghapus koridor yang masih memiliki bus aktif.',
            ], 422);
        }

        $route->delete(); // soft delete

        return response()->json([
            'message' => 'Koridor berhasil dihapus.',
        ]);
    }

    private function formatRoute(Route $route): array
    {
        return [
            'id'                   => $route->id,
            'code'                 => $route->code,
            'name'                 => $route->name,
            'is_active'            => $route->is_active,
            'total_vehicles'       => $route->vehicles_count ?? 0,
            'active_vehicles'      => $route->active_vehicles_count ?? 0,
            'total_stops'          => $route->stops_count ?? 0,
        ];
    }
}