<?php

namespace App\Services\Report;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Paymentable;
use Illuminate\Support\Carbon;

class YearlyReport
{
    public function __construct(
        private Company $company,
        private int $year,
        private bool $convertToMainCurrency = false,
    ) {
    }

    public function run(): array
    {
        $baseCurrencyId = (int) ($this->company->settings->currency_id ?: 1);
        $start = sprintf('%d-01-01', $this->year);
        $end = sprintf('%d-12-31', $this->year);
        $currencies = [];
        $categoryIds = [];

        Payment::query()
            ->where('company_id', $this->company->id)
            ->where('is_deleted', false)
            ->whereIn('status_id', [
                Payment::STATUS_COMPLETED,
                Payment::STATUS_PARTIALLY_REFUNDED,
                Payment::STATUS_REFUNDED,
            ])
            ->whereBetween('date', [$start, $end])
            ->select(['date', 'amount', 'refunded', 'currency_id', 'exchange_rate'])
            ->cursor()
            ->each(function (Payment $payment) use (&$currencies, $baseCurrencyId): void {
                $currencyId = $this->convertToMainCurrency
                    ? $baseCurrencyId
                    : (int) ($payment->currency_id ?: $baseCurrencyId);
                $month = Carbon::parse($payment->date)->month;
                $amount = ((float) $payment->amount - (float) $payment->refunded)
                    * ($this->convertToMainCurrency ? ((float) $payment->exchange_rate ?: 1) : 1);

                $currencies[$currencyId]['payments'][$month] =
                    ($currencies[$currencyId]['payments'][$month] ?? 0)
                    + $amount;
            });

        Paymentable::query()
            ->join('payments', 'payments.id', '=', 'paymentables.payment_id')
            ->join('invoices', 'invoices.id', '=', 'paymentables.paymentable_id')
            ->where('payments.company_id', $this->company->id)
            ->where('payments.is_deleted', false)
            ->whereNull('payments.deleted_at')
            ->where('paymentables.paymentable_type', 'invoices')
            ->whereIn('payments.status_id', [
                Payment::STATUS_COMPLETED,
                Payment::STATUS_PARTIALLY_REFUNDED,
                Payment::STATUS_REFUNDED,
            ])
            ->whereBetween('payments.date', [$start, $end])
            ->select([
                'payments.date as payment_date',
                'payments.currency_id',
                'payments.exchange_rate',
                'invoices.status_id as invoice_status_id',
                'paymentables.amount as applied_amount',
                'paymentables.refunded as applied_refunded',
            ])
            ->cursor()
            ->each(function (Paymentable $paymentable) use (&$currencies, $baseCurrencyId): void {
                $currencyId = $this->convertToMainCurrency
                    ? $baseCurrencyId
                    : (int) ($paymentable->currency_id ?: $baseCurrencyId);
                $month = Carbon::parse($paymentable->payment_date)->month;
                $statusId = (int) $paymentable->invoice_status_id;
                $amount = ((float) $paymentable->applied_amount - (float) $paymentable->applied_refunded)
                    * ($this->convertToMainCurrency ? ((float) $paymentable->exchange_rate ?: 1) : 1);

                $currencies[$currencyId]['payment_invoice_statuses'][$month][$statusId] =
                    ($currencies[$currencyId]['payment_invoice_statuses'][$month][$statusId] ?? 0)
                    + $amount;
            });

        Expense::query()
            ->where('company_id', $this->company->id)
            ->where('is_deleted', false)
            ->whereBetween('date', [$start, $end])
            ->select(['date', 'amount', 'currency_id', 'category_id', 'exchange_rate'])
            ->cursor()
            ->each(function (Expense $expense) use (&$currencies, &$categoryIds, $baseCurrencyId): void {
                $currencyId = $this->convertToMainCurrency
                    ? $baseCurrencyId
                    : (int) ($expense->currency_id ?: $baseCurrencyId);
                $categoryId = (int) ($expense->category_id ?: 0);
                $month = Carbon::parse($expense->date)->month;
                $amount = (float) $expense->amount
                    * ($this->convertToMainCurrency ? ((float) $expense->exchange_rate ?: 1) : 1);

                $currencies[$currencyId]['expenses'][$categoryId][$month] =
                    ($currencies[$currencyId]['expenses'][$categoryId][$month] ?? 0)
                    + $amount;

                if ($categoryId) {
                    $categoryIds[$categoryId] = true;
                }
            });

        $currencyRows = Currency::query()
            ->whereIn('id', array_keys($currencies))
            ->get()
            ->keyBy(fn (Currency $currency) => (int) $currency->id);

        $categoryRows = ExpenseCategory::withTrashed()
            ->whereIn('id', array_keys($categoryIds))
            ->get()
            ->keyBy(fn (ExpenseCategory $category) => (int) $category->id);

        ksort($currencies);

        return [
            'year' => $this->year,
            'currencies' => array_map(
                function (array $values, int|string $currencyId) use ($currencyRows, $categoryRows): array {
                    $currencyId = (int) $currencyId;
                    $currency = $currencyRows->get($currencyId);
                    $payments = [];

                    for ($month = 1; $month <= 12; $month++) {
                        $total = round($values['payments'][$month] ?? 0, 2);
                        $invoiceStatuses = array_map(
                            fn (float|int $amount): float => round($amount, 2),
                            $values['payment_invoice_statuses'][$month] ?? [],
                        );
                        $unapplied = round($total - array_sum($invoiceStatuses), 2);

                        if ($unapplied > 0) {
                            $invoiceStatuses[0] = $unapplied;
                        }

                        ksort($invoiceStatuses);

                        $payments[] = [
                            'month' => $month,
                            'invoice_statuses' => (object) $invoiceStatuses,
                            'total' => $total,
                        ];
                    }

                    $expenses = [];

                    foreach ($values['expenses'] ?? [] as $categoryId => $months) {
                        $categoryId = (int) $categoryId;
                        $category = $categoryRows->get($categoryId);
                        $monthlyTotals = [];

                        for ($month = 1; $month <= 12; $month++) {
                            $monthlyTotals[] = round($months[$month] ?? 0, 2);
                        }

                        $expenses[] = [
                            'category_id' => $categoryId ?: null,
                            'category' => $category?->name ?: ($categoryId ? 'Unknown category' : 'No category'),
                            'months' => $monthlyTotals,
                            'total' => round(array_sum($monthlyTotals), 2),
                        ];
                    }

                    usort($expenses, fn (array $left, array $right) => strnatcasecmp($left['category'], $right['category']));

                    return [
                        'currency_id' => (string) $currencyId,
                        'currency_code' => $currency?->code ?: (string) $currencyId,
                        'currency_name' => $currency?->name ?: (string) $currencyId,
                        'currency_symbol' => $currency?->symbol ?: '',
                        'payments' => $payments,
                        'expenses' => $expenses,
                    ];
                },
                $currencies,
                array_keys($currencies),
            ),
        ];
    }
}
