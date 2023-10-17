<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2023. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Http\Controllers\Reports;

use App\Utils\Traits\MakesHash;
use App\Export\CSV\VendorExport;
use App\Jobs\Report\SendToAdmin;
use App\Jobs\Report\PreviewReport;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Report\GenericReportRequest;

class VendorReportController extends BaseController
{
    use MakesHash;

    private string $filename = 'vendors.csv';

    public function __construct()
    {
        parent::__construct();
    }

    public function __invoke(GenericReportRequest $request)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        if ($request->has('send_email') && $request->get('send_email')) {
            SendToAdmin::dispatch($user->company(), $request->all(), VendorExport::class, $this->filename);

            return response()->json(['message' => 'working...'], 200);
        }
        // expect a list of visible fields, or use the default

        if($request->has('output') && $request->input('output') == 'json') {

            $hash = \Illuminate\Support\Str::uuid();

            PreviewReport::dispatch($user->company(), $request->all(), VendorExport::class, $hash);

            return response()->json(['message' => $hash], 200);
        }

        $export = new VendorExport($user->company(), $request->all());

        $csv = $export->run();

        $headers = [
            'Content-Disposition' => 'attachment',
            'Content-Type' => 'text/csv',
        ];

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $this->filename, $headers);
    }
}
