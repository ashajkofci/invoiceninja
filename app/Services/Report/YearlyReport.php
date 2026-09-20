<?php

namespace App\Services\Report;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use Illuminate\Support\Carbon;

class YearlyReport
{
    public function __construct(
        private Company $company,
        private int $year,
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
            ->select(['date', 'amount', 'refunded', 'currency_id'])
            ->cursor()
            ->each(function (Payment $payment) use (&$currencies, $baseCurrencyId): void {
                $currencyId = (int) ($payment->currency_id ?: $baseCurrencyId);
                $month = Carbon::parse($payment->date)->month;

                $currencies[$currencyId]['payments'][$month] =
                    ($currencies[$currencyId]['payments'][$month] ?? 0)
                    + (float) $payment->amount
                    - (float) $payment->refunded;
            });

        Expense::query()
            ->where('company_id', $this->company->id)
            ->where('is_deleted', false)
            ->whereBetween('date', [$start, $end])
            ->select(['date', 'amount', 'currency_id', 'category_id'])
            ->cursor()
            ->each(function (Expense $expense) use (&$currencies, &$categoryIds, $baseCurrencyId): void {
                $currencyId = (int) ($expense->currency_id ?: $baseCurrencyId);
                $categoryId = (int) ($expense->category_id ?: 0);
                $month = Carbon::parse($expense->date)->month;

                $currencies[$currencyId]['expenses'][$categoryId][$month] =
                    ($currencies[$currencyId]['expenses'][$categoryId][$month] ?? 0)
                    + (float) $expense->amount;

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
                        $payments[] = [
                            'month' => $month,
                            'total' => round($values['payments'][$month] ?? 0, 2),
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
