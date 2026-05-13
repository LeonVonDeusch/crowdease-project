<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * VehicleController (Admin)
 *
 * GET    /api/v1/admin/vehicles          → list semua bus
 * POST   /api/v1/admin/vehicles          → tambah bus baru
 * GET    /api/v1/admin/vehicles/{id}     → detail bus + density log terbaru
 * PUT    /api/v1/admin/vehicles/{id}     → update data bus
 * DELETE /api/v1/admin/vehicles/{id}     → soft delete bus
 */
class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'route_id'  => ['nullable', 'integer', 'exists:routes,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $vehicles = Vehicle::with('route')
            ->when($request->filled('route_id'), fn ($q) => $q->onRoute($request->route_id))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('plate_number')
            ->get()
            ->map(fn (Vehicle $v) => $this->formatVehicle($v));

        return response()->json(['data' => $vehicles]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plate_number' => ['required', 'string', 'max:20', 'unique:vehicles,plate_number'],
            'route_id'     => ['required', 'integer', 'exists:routes,id'],
            'capacity'     => ['required', 'integer', 'min:1', 'max:300'],
            'is_active'    => ['sometimes', 'boolean'],
        ]);

        $vehicle = Vehicle::create($validated);
        $vehicle->load('route');

        return response()->json([
            'message' => 'Bus berhasil ditambahkan.',
            'data'    => $this->formatVehicle($vehicle),
        ], 201);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        $vehicle->load(['route', 'latestDensityLog', 'forecasts' => fn ($q) => $q->ordered()]);

        return response()->json([
            'data' => [
                'id'           => $vehicle->id,
                'plate_number' => $vehicle->plate_number,
                'capacity'     => $vehicle->capacity,
                'is_active'    => $vehicle->is_active,
                'route'        => [
                    'id'   => $vehicle->route->id,
                    'code' => $vehicle->route->code,
                    'name' => $vehicle->route->name,
                ],
                'current_density' => $vehicle->latestDensityLog ? [
                    'passenger_count'  => $vehicle->latestDensityLog->passenger_count,
                    'occupancy_ratio'  => $vehicle->latestDensityLog->occupancy_ratio,
                    'occupancy_level'  => $vehicle->latestDensityLog->occupancy_level,
                    'recorded_at'      => $vehicle->latestDensityLog->recorded_at->toIso8601String(),
                ] : null,
                'forecasts' => $vehicle->forecasts->map(fn ($f) => [
                    'minutes_ahead'             => $f->minutes_ahead,
                    'predicted_occupancy_level' => $f->predicted_occupancy_level,
                    'predicted_for'             => $f->predicted_for->toIso8601String(),
                ]),
            ],
        ]);
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $validated = $request->validate([
            'plate_number' => ['sometimes', 'string', 'max:20', 'unique:vehicles,plate_number,' . $vehicle->id],
            'route_id'     => ['sometimes', 'integer', 'exists:routes,id'],
            'capacity'     => ['sometimes', 'integer', 'min:1', 'max:300'],
            'is_active'    => ['sometimes', 'boolean'],
        ]);

        $vehicle->update($validated);
        $vehicle->load('route');

        return response()->json([
            'message' => 'Data bus berhasil diperbarui.',
            'data'    => $this->formatVehicle($vehicle->fresh(['route'])),
        ]);
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $vehicle->update(['is_active' => false]);
        $vehicle->delete(); // soft delete

        return response()->json([
            'message' => 'Bus berhasil dihapus.',
        ]);
    }

    private function formatVehicle(Vehicle $vehicle): array
    {
        return [
            'id'              => $vehicle->id,
            'plate_number'    => $vehicle->plate_number,
            'capacity'        => $vehicle->capacity,
            'is_active'       => $vehicle->is_active,
            'route'           => $vehicle->route ? [
                'id'   => $vehicle->route->id,
                'code' => $vehicle->route->code,
                'name' => $vehicle->route->name,
            ] : null,
            'occupancy_level' => $vehicle->current_occupancy_level,
            'occupancy_ratio' => $vehicle->current_occupancy_ratio,
            'marker_color'    => $vehicle->marker_color,
        ];
    }
}