<?php

namespace Modules\NsManufacturing\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\NsManufacturing\Models\WorkOrder;
use Modules\NsManufacturing\Services\ManufacturingService;

class WorkOrderApiController extends Controller
{
    protected $service;
    public function __construct(ManufacturingService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        return response()->json(WorkOrder::with('lines')->paginate(25));
    }

    public function show($id)
    {
        return response()->json(WorkOrder::with('lines')->findOrFail($id));
    }

    public function start($id)
    {
        $wo = WorkOrder::findOrFail($id);
        $wo->status = 'started';
        $wo->started_at = now();
        $wo->save();
        return response()->json($wo);
    }

    public function complete($id)
    {
        $wo = WorkOrder::findOrFail($id);
        $this->service->completeWorkOrder($wo, auth()->id());
        return response()->json($wo->fresh());
    }
}