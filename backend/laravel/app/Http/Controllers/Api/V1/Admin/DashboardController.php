<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DensityLog;
use App\Models\Route;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DashboardController
 *
 * GET /api/v1/admin/dashboard/summary  → ringkasan kepadatan real-time
 * GET /api/v1/admin/dashboard/hourly   → rata-rata per jam (hari ini)
 */
class DashboardController extends Controller
{
    /**
     * Ringkasan kepadatan semua koridor saat ini.
     */
    public function summary(): JsonResponse
    {
        $routes = Route::active()
            ->with(['activeVehicles.latestDensityLog'])
            ->get();

        $summary = $routes->map(function (Route $route) {
            $vehicles = $route->activeVehicles;

            $levels = $vehicles
                ->filter(fn ($v) => $v->latestDensityLog !== null)
                ->countBy(fn ($v) => $v->latestDensityLog->occupancy_level);

            return [
                'route_id'   => $route->id,
                'route_code' => $route->code,
                'route_name' => $route->name,
                'vehicles'   => [
                    'total'  => $vehicles->count(),
                    'low'    => $levels->get('low', 0),
                    'medium' => $levels->get('medium', 0),
                    'high'   => $levels->get('high', 0),
                    'overcrowded' => $levels->get('overcrowded', 0),
                    'no_data' => $vehicles->filter(fn ($v) => $v->latestDensityLog === null)->count(),
                ],
                'avg_occupancy_ratio' => $route->avg_occupancy_ratio,
            ];
        });

        // Total armada
        $totalVehicles  = Vehicle::active()->count();
        $totalWithData  = Vehicle::active()
            ->whereHas('latestDensityLog')
            ->count();

        return response()->json([
            'data' => [
                'generated_at'   => now('Asia/Jakarta')->toIso8601String(),
                'total_vehicles' => $totalVehicles,
                'vehicles_online' => $totalWithData,
                'routes'         => $summary,
            ],
        ]);
    }

    /**
     * Rata-rata occupancy per jam untuk hari ini — untuk Chart.js di dasbor.
     */
    public function hourly(Request $request): JsonResponse
    {
        $request->validate([
            'route_id' => ['nullable', 'integer', 'exists:routes,id'],
            'date'     => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date    = $request->input('date', now('Asia/Jakarta')->toDateString());
        $routeId = $request->input('route_id');

        $query = DensityLog::query()
            ->selectRaw('HOUR(recorded_at) as hour, AVG(occupancy_ratio) as avg_ratio, COUNT(*) as total_readings')
            ->whereDate('recorded_at', $date)
            ->groupByRaw('HOUR(recorded_at)')
            ->orderByRaw('HOUR(recorded_at)');

        if ($routeId) {
            $query->whereHas('vehicle', fn ($q) => $q->where('route_id', $routeId));
        }

        $rows = $query->get();

        // Isi jam yang kosong dengan null agar grafik tidak meloncat
        $hourly = collect(range(0, 23))->map(function (int $h) use ($rows) {
            $row = $rows->firstWhere('hour', $h);

            return [
                'hour'           => $h,
                'label'          => sprintf('%02d:00', $h),
                'avg_ratio'      => $row ? round($row->avg_ratio, 4) : null,
                'total_readings' => $row ? (int) $row->total_readings : 0,
            ];
        });

        return response()->json([
            'data' => [
                'date'    => $date,
                'hourly'  => $hourly,
            ],
        ]);
    }
}