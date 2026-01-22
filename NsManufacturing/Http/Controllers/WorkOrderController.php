<?php

namespace Modules\NsManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\NsManufacturing\Models\WorkOrder;
use Modules\NsManufacturing\Models\WorkOrderLine;
use Modules\NsManufacturing\Services\ManufacturingService;

class WorkOrderController extends Controller
{
    protected $service;

    public function __construct(ManufacturingService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        $workOrders = WorkOrder::with('product')->paginate(25);
        return view('nsmanufacturing::work_orders.index', compact('workOrders'));
    }

    public function create()
    {
        return view('nsmanufacturing::work_orders.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'reference' => 'required|string|unique:ns_work_orders,reference',
            'bom_id' => 'nullable|integer',
            'product_id' => 'required|integer',
            'quantity' => 'required|numeric',
            'warehouse_id' => 'nullable|integer',
        ]);

        $wo = WorkOrder::create($data);

        // If BOM exists, create work order lines based on BOM
        if ($data['bom_id']) {
            $bom = \Modules\NsManufacturing\Models\Bom::with('lines')->find($data['bom_id']);
            if ($bom) {
                foreach ($bom->lines as $line) {
                    WorkOrderLine::create([
                        'work_order_id' => $wo->id,
                        'product_id' => $line->component_product_id,
                        'quantity' => bcmul((string)$line->quantity, (string)$wo->quantity, 4),
                    ]);
                }
            }
        }

        return redirect()->route('nsmanufacturing.work_orders.index')->with('success', __('nsmanufacturing::messages.workorder_created'));
    }

    public function show($id)
    {
        $wo = WorkOrder::with('lines.product','bom')->findOrFail($id);
        return view('nsmanufacturing::work_orders.show', compact('wo'));
    }

    public function start($id)
    {
        $wo = WorkOrder::findOrFail($id);
        $wo->status = 'started';
        $wo->started_at = now();
        $wo->save();
        return redirect()->back()->with('success', __('nsmanufacturing::messages.workorder_started'));
    }

    public function complete($id)
    {
        $wo = WorkOrder::findOrFail($id);
        $this->service->completeWorkOrder($wo, auth()->id());
        return redirect()->back()->with('success', __('nsmanufacturing::messages.workorder_completed'));
    }
}