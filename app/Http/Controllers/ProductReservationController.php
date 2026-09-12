<?php

namespace App\Http\Controllers;

use App\Http\Requests\Request;
use App\Services\ProductReservation\ProductReservationService;
use App\Utils\Traits\MakesHash;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class ProductReservationController extends BaseController
{
    use MakesHash;

    public function calendar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'product_id' => ['sometimes', 'string'],
        ]);

        $service = $this->service();
        $this->ensureEnabled($service);
        $productId = isset($validated['product_id']) ? $this->decodePrimaryKey($validated['product_id']) : null;

        return response()->json(['data' => $service->calendar(
            $validated['start_date'],
            $validated['end_date'],
            $productId
        )]);
    }

    public function availability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'product_id' => ['sometimes', 'string'],
        ]);

        $service = $this->service();
        $this->ensureEnabled($service);
        $productId = isset($validated['product_id']) ? $this->decodePrimaryKey($validated['product_id']) : null;

        return response()->json(['data' => $service->availability(
            $validated['start_date'],
            $validated['end_date'],
            [],
            null,
            $productId
        )]);
    }

    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice' => ['required', 'array'],
            'invoice.line_items' => ['sometimes', 'array'],
            'invoice.id' => ['sometimes', 'string'],
            'entity_type' => ['sometimes', 'in:invoice,quote'],
        ]);

        $service = $this->service();
        $this->ensureEnabled($service);

        try {
            [$start, $end] = $service->datesFromInvoice($validated['invoice']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => []], 422);
        }

        $invoiceId = ($validated['entity_type'] ?? 'invoice') === 'invoice'
            ? data_get($validated, 'invoice.id')
            : null;
        $invoiceId = $invoiceId ? $this->decodePrimaryKey($invoiceId) : null;
        $availability = $service->availability(
            $start,
            $end,
            data_get($validated, 'invoice.line_items', []),
            $invoiceId
        );

        return response()->json([
            'data' => $availability,
            'overbooked' => array_values(array_filter($availability, fn ($item) => $item['is_overbooked'])),
        ]);
    }

    private function service(): ProductReservationService
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        abort_unless(
            $user->isAdmin()
                || $user->hasPermission('view_invoice')
                || $user->hasPermission('edit_invoice')
                || $user->hasPermission('create_invoice'),
            403
        );

        return new ProductReservationService($user->company());
    }

    private function ensureEnabled(ProductReservationService $service): void
    {
        abort_unless(
            $service->enabled(),
            403,
            'The product reservation calendar is not enabled and configured for this company.'
        );
    }
}
