<?php

namespace Modules\NsManufacturing\Http\Controllers;

use App\Http\Controllers\DashboardController;
use Modules\NsManufacturing\Crud\BomCrud;
use Modules\NsManufacturing\Crud\BomItemCrud;
use Modules\NsManufacturing\Crud\ProductionOrderCrud;
use Modules\NsManufacturing\Models\ManufacturingBom;
use Modules\NsManufacturing\Models\ManufacturingOrder;
use Modules\NsManufacturing\Models\ManufacturingBomItem;
use Modules\NsManufacturing\Services\ProductionService;
use Illuminate\Http\Request;

class ManufacturingController extends DashboardController
{
    public function __construct(
        protected ProductionService $productionService,
        protected \Modules\NsManufacturing\Services\AnalyticsService $analyticsService,
        protected \App\Services\DateService $dateService
    ) {
        parent::__construct($dateService);
    }

    // BOMs
    public function boms() { return BomCrud::table(); }
    public function createBom() { return BomCrud::form(); }
    public function editBom($id) { return BomCrud::form(ManufacturingBom::findOrFail($id)); }

    // Feature: Explode BOM
    public function explodeBom($id) {
        $bom = ManufacturingBom::with('items.product.unit')->findOrFail($id);
        return view('ns-manufacturing::boms.explode', compact('bom'));
    }
    
    // BOM Items
    public function bomItems() { return BomItemCrud::table(); }
    public function createBomItem() { return BomItemCrud::form(); }
    public function editBomItem($id) { return BomItemCrud::form(ManufacturingBomItem::findOrFail($id)); }

    // Orders
    public function orders() { return ProductionOrderCrud::table(); }
    public function createOrder() { return ProductionOrderCrud::form(); }
    public function editOrder($id) { return ProductionOrderCrud::form(ManufacturingOrder::findOrFail($id)); }

    /**
     * Custom Action: Start Order
     */
    public function startOrder($id)
    {
        try {
            $order = ManufacturingOrder::findOrFail($id);
            $this->productionService->startOrder($order);
            
            return response()->json([
                'status' => 'success',
                'message' => __('Order started successfully.')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Custom Action: Complete Order
     */
    public function completeOrder($id)
    {
        try {
            $order = ManufacturingOrder::findOrFail($id);
            $this->productionService->completeOrder($order);
            
            return response()->json([
                'status' => 'success',
                'message' => __('Order completed successfully.')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function reports()
    {
        return view('ns-manufacturing::reports.index');
    }
}
