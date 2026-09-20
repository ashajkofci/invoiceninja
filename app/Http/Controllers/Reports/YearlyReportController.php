<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\BaseController;
use App\Services\Report\YearlyReport;
use Illuminate\Http\Request;

class YearlyReportController extends BaseController
{
    public function __invoke(Request $request)
    {
        $year = $request->validate([
            'year' => ['required', 'integer', 'between:1900,9999'],
            'convert_to_main_currency' => ['sometimes', 'boolean'],
        ]);

        return response()->json(
            (new YearlyReport(
                $request->user()->company(),
                $year['year'],
                $year['convert_to_main_currency'] ?? false,
            ))->run()
        );
    }
}
