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
        ])['year'];

        return response()->json(
            (new YearlyReport($request->user()->company(), $year))->run()
        );
    }
}
