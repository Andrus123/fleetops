<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\FuelReportExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\FleetOps\Models\FuelReport;
use Fleetbase\FleetOps\Imports\FleetImport;
use Fleetbase\Models\File;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class FuelReportController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'fuel_report';

    /**
     * Export the fleets to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('fuel_report-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new FuelReportExport($selections), $fileName);
    }

    public function import(ImportRequest $request)
    {
        $disk           = $request->input('disk', config('filesystems.default'));
        $files          = $request->input('files');
        $files          = File::whereIn('uuid', $files)->get();
        $validFileTypes = ['csv', 'tsv', 'xls', 'xlsx'];
        $imports        = collect();

        foreach ($files as $file) {
            // validate file type
            if (!Str::endsWith($file->path, $validFileTypes)) {
                return response()->error('Invalid file uploaded, must be one of the following: ' . implode(', ', $validFileTypes));
            }

            try {
                $data = Excel::toArray(new FleetImport(), $file->path, $disk);
            } catch (\Exception $e) {
                return response()->error('Invalid file, unable to proccess.');
            }

            if (count($data) === 1) {
                $imports = $imports->concat($data[0]);
            }
        }

        $imports = $imports->map(
            function ($row) {

               // handle created at
                if (isset($row['created at'])) {
                    $row['created_at'] = $row['created at'];
                     unset($row['created at']);
                }

               // Handle id
               if (isset($row['id'])) {
                $row['public_id'] = $row['id'];
                unset($row['id']);
            }
               // set default values
               $row['company_uuid'] = session('company');
                return $row;
            })->values()->toArray();

        FuelReport::bulkInsert($imports);

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'count' => count($imports)]);
    }
}
