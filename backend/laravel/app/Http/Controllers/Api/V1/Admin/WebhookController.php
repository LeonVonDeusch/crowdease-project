<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Services\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WebhookController (Admin)
 *
 * GET    /api/v1/admin/webhooks                  → list semua webhook
 * POST   /api/v1/admin/webhooks                  → daftarkan webhook baru
 * GET    /api/v1/admin/webhooks/{id}             → detail + statistik
 * PUT    /api/v1/admin/webhooks/{id}             → update webhook
 * DELETE /api/v1/admin/webhooks/{id}             → hapus webhook
 * POST   /api/v1/admin/webhooks/{id}/test        → kirim ping test
 * GET    /api/v1/admin/webhooks/{id}/deliveries  → log pengiriman
 */
class WebhookController extends Controller
{
    public function __construct(private readonly WebhookDispatcher $dispatcher) {}

    public function index(): JsonResponse
    {
        $webhooks = Webhook::orderByDesc('created_at')
            ->get()
            ->map(fn (Webhook $w) => $this->formatWebhook($w));

        return response()->json(['data' => $webhooks]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'   => ['required', 'string', 'max:100'],
            'url'    => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookDispatcher::VALID_EVENTS)],
        ]);

        $webhook = Webhook::create([
            'name'      => $request->input('name'),
            'url'       => $request->input('url'),
            'events'    => $request->input('events'),
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Webhook berhasil didaftarkan. Simpan secret ini — tidak akan ditampilkan lagi.',
            'data'    => array_merge($this->formatWebhook($webhook), [
                'secret' => $webhook->secret, // hanya muncul sekali saat create
            ]),
        ], 201);
    }

    public function show(Webhook $webhook): JsonResponse
    {
        return response()->json([
            'data' => array_merge($this->formatWebhook($webhook), [
                'target_type' => $webhook->getTargetType(),
                'recent_deliveries' => $webhook->deliveries()
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get()
                    ->map(fn ($d) => [
                        'delivery_id'   => $d->delivery_id,
                        'event'         => $d->event,
                        'status'        => $d->status,
                        'response_code' => $d->response_code,
                        'attempt'       => $d->attempt,
                        'created_at'    => $d->created_at->toIso8601String(),
                    ]),
            ]),
        ]);
    }

    public function update(Request $request, Webhook $webhook): JsonResponse
    {
        $request->validate([
            'name'     => ['sometimes', 'string', 'max:100'],
            'url'      => ['sometimes', 'url', 'max:500'],
            'events'   => ['sometimes', 'array', 'min:1'],
            'events.*' => [Rule::in(WebhookDispatcher::VALID_EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $webhook->update($request->only(['name', 'url', 'events', 'is_active']));

        return response()->json([
            'message' => 'Webhook berhasil diperbarui.',
            'data'    => $this->formatWebhook($webhook->fresh()),
        ]);
    }

    public function destroy(Webhook $webhook): JsonResponse
    {
        $webhook->delete();

        return response()->json(['message' => 'Webhook berhasil dihapus.']);
    }

    /**
     * Kirim event test langsung (synchronous) untuk verifikasi endpoint.
     */
    public function test(Webhook $webhook): JsonResponse
    {
        $result = $this->dispatcher->dispatchNow($webhook, 'density.recorded', [
            'vehicle' => [
                'plate_number' => 'TEST-0000',
                'route_code'   => 'K0',
            ],
            'density' => [
                'passenger_count'  => 30,
                'capacity'         => 60,
                'occupancy_ratio'  => 0.5,
                'occupancy_level'  => 'medium',
                'recorded_at'      => now('Asia/Jakarta')->toIso8601String(),
            ],
        ]);

        $status = $result['success'] ? 200 : 502;

        return response()->json([
            'message'       => $result['success'] ? 'Ping berhasil dikirim.' : 'Ping gagal dikirim.',
            'success'       => $result['success'],
            'status_code'   => $result['status_code'],
            'response_body' => $result['response_body'],
        ], $status);
    }

    /**
     * Riwayat pengiriman webhook — untuk halaman log di dasbor operator.
     */
    public function deliveries(Request $request, Webhook $webhook): JsonResponse
    {
        $request->validate([
            'status'   => ['nullable', 'string', Rule::in(['pending', 'delivered', 'failed', 'permanently_failed'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $deliveries = $webhook->deliveries()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => $deliveries->map(fn ($d) => [
                'id'            => $d->id,
                'delivery_id'   => $d->delivery_id,
                'event'         => $d->event,
                'status'        => $d->status,
                'response_code' => $d->response_code,
                'response_body' => $d->response_body,
                'attempt'       => $d->attempt,
                'delivered_at'  => $d->delivered_at?->toIso8601String(),
                'created_at'    => $d->created_at->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $deliveries->currentPage(),
                'last_page'    => $deliveries->lastPage(),
                'per_page'     => $deliveries->perPage(),
                'total'        => $deliveries->total(),
            ],
        ]);
    }

    private function formatWebhook(Webhook $webhook): array
    {
        return [
            'id'                 => $webhook->id,
            'name'               => $webhook->name,
            'url'                => $webhook->url,
            'events'             => $webhook->events,
            'is_active'          => $webhook->is_active,
            'total_deliveries'   => $webhook->total_deliveries,
            'failed_deliveries'  => $webhook->failed_deliveries,
            'last_triggered_at'  => $webhook->last_triggered_at?->toIso8601String(),
            'created_at'         => $webhook->created_at->toIso8601String(),
        ];
    }
}