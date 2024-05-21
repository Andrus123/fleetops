<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\FleetExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Http\Requests\Internal\FleetActionRequest;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Fleet;
use Fleetbase\FleetOps\Models\FleetDriver;
use Fleetbase\FleetOps\Models\FleetVehicle;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\FleetOps\Imports\FleetImport;
use Fleetbase\Models\File;
use Fleetbase\Http\Requests\ImportRequest;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class FleetController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'fleet';

    /**
     * Export the fleets to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public static function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('fleets-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new FleetExport($selections), $fileName);
    }

    /**
     * Removes a driver from a fleet.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public static function removeDriver(FleetActionRequest $request)
    {
        $fleet  = Fleet::where('uuid', $request->input('fleet'))->first();
        $driver = Driver::where('uuid', $request->input('driver'))->first();

        // check if driver is already in this fleet
        $deleted = FleetDriver::where([
            'fleet_uuid'  => $fleet->uuid,
            'driver_uuid' => $driver->uuid,
        ])->delete();

        return response()->json([
            'status'  => 'ok',
            'deleted' => $deleted,
        ]);
    }

    /**
     * Adds a driver to a fleet.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public static function assignDriver(FleetActionRequest $request)
    {
        $fleet  = Fleet::where('uuid', $request->input('fleet'))->first();
        $driver = Driver::where('uuid', $request->input('driver'))->first();
        $added  = false;

        // check if driver is already in this fleet
        $exists = FleetDriver::where([
            'fleet_uuid'  => $fleet->uuid,
            'driver_uuid' => $driver->uuid,
        ])->exists();

        if (!$exists) {
            $added = FleetDriver::create([
                'fleet_uuid'  => $fleet->uuid,
                'driver_uuid' => $driver->uuid,
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'exists' => $exists,
            'added'  => (bool) $added,
        ]);
    }

    /**
     * Removes a vehicle from a fleet.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public static function removeVehicle(FleetActionRequest $request)
    {
        $fleet   = Fleet::where('uuid', $request->input('fleet'))->first();
        $vehicle = Vehicle::where('uuid', $request->input('vehicle'))->first();

        // check if vehicle is already in this fleet
        $deleted = FleetVehicle::where([
            'fleet_uuid'   => $fleet->uuid,
            'vehicle_uuid' => $vehicle->uuid,
        ])->delete();

        return response()->json([
            'status'  => 'ok',
            'deleted' => $deleted,
        ]);
    }

    /**
     * Adds a vehicle to a fleet.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public static function assignVehicle(FleetActionRequest $request)
    {
        $fleet   = Fleet::where('uuid', $request->input('fleet'))->first();
        $vehicle = Vehicle::where('uuid', $request->input('vehicle'))->first();
        $added   = false;

        // check if vehicle is already in this fleet
        $exists = FleetVehicle::where([
            'fleet_uuid'   => $fleet->uuid,
            'vehicle_uuid' => $vehicle->uuid,
        ])->exists();

        if (!$exists) {
            $added = FleetVehicle::create([
                'fleet_uuid'   => $fleet->uuid,
                'vehicle_uuid' => $vehicle->uuid,
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'exists' => $exists,
            'added'  => (bool) $added,
        ]);
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
                    $row['id'] = $row['id'];
                    unset($row['id']);
                }

                return $row;
            })->values()->toArray();
        
            
        Fleet::bulkInsert($imports);
        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'count' => count($imports)]);
    }
}
