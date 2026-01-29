<?php

namespace Modules\NsManufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\NsManufacturing\Models\ManufacturingOrder;
use Modules\NsManufacturing\Models\ManufacturingBom;
use Modules\NsManufacturing\Services\BomService;
use Modules\NsManufacturing\Services\AnalyticsService;

class ManufacturingReportController extends Controller
{
    public function __construct(
        protected BomService $bomService,
        protected AnalyticsService $analyticsService
    ) {}

    /**
     * Get summary metrics for the dashboard
     */
    public function getSummary(Request $request)
    {
        $query = ManufacturingOrder::query();

        if ($request->from) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->to) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $orders = $query->get();
        $completed = $orders->where('status', ManufacturingOrder::STATUS_COMPLETED);
        
        $totalValue = 0;
        foreach ($completed as $order) {
            $unitCost = $order->bom ? $this->bomService->calculateEstimatedCost($order->bom) : 0;
            $totalValue += $unitCost * $order->quantity;
        }

        return response()->json([
            'total_orders' => $orders->count(),
            'completed_orders' => $completed->count(),
            'pending_orders' => $orders->where('status', '!=', ManufacturingOrder::STATUS_COMPLETED)->count(),
            'total_value' => (float) $totalValue,
            'total_value_formatted' => ns()->currency->define($totalValue)->format(),
        ]);
    }

    /**
     * Get detailed production history
     */
    public function getHistory(Request $request)
    {
        $query = ManufacturingOrder::with(['product', 'unit', 'bom'])
            ->orderBy('created_at', 'desc');

        if ($request->from) $query->whereDate('created_at', '>=', $request->from);
        if ($request->to) $query->whereDate('created_at', '<=', $request->to);
        if ($request->status) $query->where('status', $request->status);
        if ($request->product_id) $query->where('product_id', $request->product_id);

        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);

        $orders = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $orders->getCollection()->map(function($order) {
                $unitCost = $order->bom ? $this->bomService->calculateEstimatedCost($order->bom) : 0;
                return [
                    'id' => $order->id,
                    'code' => $order->code,
                    'date' => $order->created_at->format('Y-m-d H:i'),
                    'product' => ($order->product->name ?? __('Unknown')) . ' (' . ($order->unit->name ?? '') . ')',
                    'quantity' => $order->quantity,
                    'status' => ucwords(str_replace('_', ' ', $order->status)),
                    'status_raw' => $order->status,
                    'value' => ns()->currency->define($unitCost * $order->quantity)->format(),
                ];
            }),
            'total' => $orders->total(),
            'last_page' => $orders->lastPage(),
            'current_page' => $orders->currentPage(),
        ]);
    }

    /**
     * Get ingredient consumption report
     */
    public function getConsumption(Request $request)
    {
        // This query joins production orders -> ingredients (via BOM items) 
        // to aggregate total consumption
        $query = DB::table('ns_manufacturing_orders as o')
            ->join('ns_manufacturing_bom_items as i', 'o.bom_id', '=', 'i.bom_id')
            ->join('nexopos_products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('nexopos_units as u', 'u.id', '=', 'i.unit_id')
            ->where('o.status', ManufacturingOrder::STATUS_COMPLETED)
            ->select(
                'p.name as ingredient_name',
                'u.name as unit_name',
                DB::raw('SUM(i.quantity * o.quantity) as total_quantity'),
                'p.id as product_id'
            )
            ->groupBy('p.id', 'p.name', 'u.name');

        if ($request->from) $query->whereDate('o.completed_at', '>=', $request->from);
        if ($request->to) $query->whereDate('o.completed_at', '<=', $request->to);

        $consumption = $query->get();

        $productService = app()->make(\App\Services\ProductService::class);

        return response()->json([
            'data' => $consumption->map(function($item) use ($productService) {
                $product = \App\Models\Product::find($item->product_id);
                $unit = \App\Models\Unit::where('name', $item->unit_name)->first();
                $cogs = ($product && $unit) ? $productService->getCogs($product, $unit) : 0;
                
                return [
                    'ingredient' => $item->ingredient_name,
                    'unit' => $item->unit_name,
                    'quantity' => (float)$item->total_quantity,
                    'total_cost' => ns()->currency->define($item->total_quantity * $cogs)->format(),
                ];
            })
        ]);
    }

    /**
     * Filter options for the report
     */
    public function getFilters()
    {
        return response()->json([
            'products' => \App\Models\Product::where('status', 'available')->limit(100)->get(['id', 'name']),
            'statuses' => [
                ['value' => 'draft', 'label' => __('Draft')],
                ['value' => 'planned', 'label' => __('Planned')],
                ['value' => 'in_progress', 'label' => __('In Progress')],
                ['value' => 'completed', 'label' => __('Completed')],
            ]
        ]);
    }
}
