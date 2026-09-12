<?php

namespace App\Services\ProductReservation;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ProductReservationService
{
    public function __construct(private Company $company)
    {
    }

    public function enabled(): bool
    {
        return (bool) ($this->company->enabled_modules & Company::MODULE_PRODUCT_RESERVATIONS)
            && $this->startField() !== null
            && $this->endField() !== null
            && $this->startField() !== $this->endField();
    }

    public function datesFromInvoice(array $invoice): array
    {
        $start = $this->startField();
        $end = $this->endField();

        if (! $start || ! $end) {
            throw new InvalidArgumentException('Reservation date custom fields are not configured.');
        }

        return $this->normalizePeriod(data_get($invoice, $start), data_get($invoice, $end));
    }

    public function calendar(string $startDate, string $endDate, ?int $productId = null): array
    {
        [$start, $end] = $this->normalizePeriod($startDate, $endDate);
        $product = $productId
            ? Product::query()->where('company_id', $this->company->id)->findOrFail($productId)
            : null;

        $productsByKey = Product::query()
            ->where('company_id', $this->company->id)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('product_key');

        return $this->overlappingInvoices($start, $end)
            ->map(function (Invoice $invoice) use ($product, $productsByKey) {
                $items = collect($invoice->line_items)
                    ->filter(fn ($item) => (int) data_get($item, 'type_id', 1) === Product::PRODUCT_TYPE_PHYSICAL)
                    ->when($product, fn (Collection $items) => $items->where('product_key', $product->product_key))
                    ->map(fn ($item) => [
                        'product_description' => (string) (
                            data_get($item, 'notes')
                            ?: $productsByKey->get((string) data_get($item, 'product_key'))?->notes
                            ?: 'Product'
                        ),
                        'quantity' => (float) data_get($item, 'quantity', 0),
                        '_product_key' => (string) data_get($item, 'product_key', ''),
                    ])
                    ->filter(fn ($item) => $item['_product_key'] !== '')
                    ->map(fn ($item) => collect($item)->except('_product_key')->all())
                    ->values();

                if ($product && $items->isEmpty()) {
                    return null;
                }

                [$invoiceStart, $invoiceEnd] = $this->datesFromInvoice($invoice->toArray());
                $status = $this->statusFromInvoice($invoice->toArray());

                return [
                    'id' => $invoice->hashed_id,
                    'invoice_id' => $invoice->hashed_id,
                    'invoice_number' => (string) $invoice->number,
                    'client_name' => $invoice->client ? $invoice->client->present()->name() : '',
                    'start_date' => $invoiceStart,
                    'end_date' => $invoiceEnd,
                    'status' => $status,
                    'color' => $this->colorForStatus($status),
                    'products' => $items->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function availability(
        string $startDate,
        string $endDate,
        array $requestedItems = [],
        ?int $excludeInvoiceId = null,
        ?int $productId = null,
        bool $includeAllProducts = false
    ): array {
        [$start, $end] = $this->normalizePeriod($startDate, $endDate);
        $today = CarbonImmutable::now($this->company->timezone()->name)->format('Y-m-d');

        // Availability is an operational forecast. Historical reservations
        // remain visible in the calendar, but must not reduce today's stock.
        if ($end < $today) {
            return [];
        }

        $start = max($start, $today);
        $requested = $this->quantitiesByProductKey($requestedItems);
        $used = [];
        $conflicts = [];

        $this->overlappingInvoices($start, $end, $excludeInvoiceId)->each(function (Invoice $invoice) use (&$used, &$conflicts) {
            [$invoiceStart, $invoiceEnd] = $this->datesFromInvoice($invoice->toArray());
            $status = $this->statusFromInvoice($invoice->toArray());
            foreach ($this->quantitiesByProductKey((array) $invoice->line_items) as $key => $quantity) {
                $used[$key] = ($used[$key] ?? 0) + $quantity;
                $conflicts[$key][] = [
                    'invoice_id' => $invoice->hashed_id,
                    'invoice_number' => (string) $invoice->number,
                    'client_name' => $invoice->client ? $invoice->client->present()->name() : '',
                    'start_date' => $invoiceStart,
                    'end_date' => $invoiceEnd,
                    'status' => $status,
                    'color' => $this->colorForStatus($status),
                    'quantity' => $quantity,
                ];
            }
        });

        $keys = collect(array_keys($used))->merge(array_keys($requested))->unique();
        if ($productId) {
            $selectedProduct = Product::query()
                ->where('company_id', $this->company->id)
                ->findOrFail($productId);
            $keys->push($selectedProduct->product_key);
        }
        $products = Product::query()
            ->where('company_id', $this->company->id)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->when($productId, fn ($query) => $query->where('id', $productId))
            ->when(! $includeAllProducts && ! $productId, fn ($query) => $query->whereIn('product_key', $keys))
            ->get();

        return $products->map(function (Product $product) use ($used, $requested, $conflicts) {
            $reserved = (float) ($used[$product->product_key] ?? 0);
            $quantity = (float) ($requested[$product->product_key] ?? 0);
            $stock = (float) $product->in_stock_quantity;
            $tracked = $stock > 0;
            $reservations = $conflicts[$product->product_key] ?? [];

            return [
                'product_id' => $product->hashed_id,
                'product_key' => (string) $product->product_key,
                'product_description' => (string) ($product->notes ?: 'Product'),
                'stock_quantity' => $stock,
                'reserved_quantity' => $reserved,
                'requested_quantity' => $quantity,
                'total_quantity' => $reserved + $quantity,
                'available_quantity' => $tracked ? max(0, $stock - $reserved) : null,
                'is_stock_tracked' => $tracked,
                'is_overbooked' => $tracked && $reserved + $quantity > $stock,
                'reservations' => $reservations,
                'conflicting_invoices' => $reservations,
            ];
        })->values()->all();
    }

    private function overlappingInvoices(string $startDate, string $endDate, ?int $excludeInvoiceId = null): Collection
    {
        $startField = $this->startField();
        $endField = $this->endField();

        if (! $startField || ! $endField) {
            return collect();
        }

        $query = Invoice::query()
            ->with('client')
            ->where('company_id', $this->company->id)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->where('status_id', '!=', Invoice::STATUS_CANCELLED)
            ->where($startField, '<=', $endDate)
            ->where($endField, '>=', $startDate)
            ->when($excludeInvoiceId, fn ($query) => $query->where('id', '!=', $excludeInvoiceId));

        $statusField = $this->statusField();
        $visibleStatuses = collect($this->statusRules())->pluck('value')->filter()->values();
        if ($statusField && $visibleStatuses->isNotEmpty()) {
            $query->whereIn($statusField, $visibleStatuses);
        }

        return $query->get();
    }

    private function quantitiesByProductKey(array $items): array
    {
        $quantities = [];

        foreach ($items as $item) {
            if ((int) data_get($item, 'type_id', 1) !== Product::PRODUCT_TYPE_PHYSICAL) {
                continue;
            }

            $key = trim((string) data_get($item, 'product_key', ''));
            if ($key !== '') {
                $quantities[$key] = ($quantities[$key] ?? 0) + max(0, (float) data_get($item, 'quantity', 0));
            }
        }

        return $quantities;
    }

    private function normalizePeriod(mixed $startDate, mixed $endDate): array
    {
        try {
            $start = CarbonImmutable::parse((string) $startDate)->startOfDay();
            $end = CarbonImmutable::parse((string) $endDate)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Reservation dates must be valid dates.');
        }

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException('The reservation end date must be on or after the start date.');
        }

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    private function startField(): ?string
    {
        $number = (int) $this->company->reservation_start_custom_field;
        return $number >= 1 && $number <= 4 ? "custom_value{$number}" : null;
    }

    private function endField(): ?string
    {
        $number = (int) $this->company->reservation_end_custom_field;
        return $number >= 1 && $number <= 4 ? "custom_value{$number}" : null;
    }

    private function statusField(): ?string
    {
        $number = (int) $this->company->reservation_status_custom_field;
        return $number >= 1 && $number <= 4 ? "custom_value{$number}" : null;
    }

    private function statusFromInvoice(array $invoice): string
    {
        $field = $this->statusField();
        return $field ? trim((string) data_get($invoice, $field, '')) : '';
    }

    private function statusRules(): array
    {
        return collect((array) $this->company->reservation_statuses)
            ->map(fn ($rule) => [
                'value' => trim((string) data_get($rule, 'value', '')),
                'color' => (string) data_get($rule, 'color', '#2563eb'),
            ])
            ->filter(fn ($rule) => $rule['value'] !== '')
            ->values()
            ->all();
    }

    private function colorForStatus(string $status): string
    {
        $rule = collect($this->statusRules())->first(
            fn ($rule) => mb_strtolower($rule['value']) === mb_strtolower($status)
        );

        return $rule['color'] ?? '#2563eb';
    }
}
