<?php

namespace Modules\NsManufacturing\Services;

use Modules\NsManufacturing\Models\ManufacturingOrder;
use Modules\NsManufacturing\Models\ManufacturingBom;
use Exception;
use Illuminate\Support\Facades\DB;

class ProductionService
{
    public function __construct(
        protected InventoryBridgeService $inventory,
        protected BomService $bomService
    ) {}

    public function startOrder(ManufacturingOrder $order)
    {
        if ($order->status !== ManufacturingOrder::STATUS_PLANNED && $order->status !== ManufacturingOrder::STATUS_DRAFT) {
            throw new Exception("Order must be in Planned or Draft state to start.");
        }

        $bom = $order->bom;
        if (!$bom) throw new Exception("No BOM assigned to order.");

        $missing = [];
        foreach ($bom->items as $item) {
            $required = $item->quantity * $order->quantity;
            if (!$this->inventory->isAvailable($item->product_id, $item->unit_id, $required)) {
                $missing[] = $item->product ? $item->product->name : 'Unknown Product';
            }
        }

        if (!empty($missing)) {
            throw new Exception("Insufficient stock for: " . implode(', ', $missing));
        }

        DB::transaction(function() use ($order, $bom) {
            $productService = app()->make(\App\Services\ProductService::class);
            foreach ($bom->items as $item) {
                $required = $item->quantity * $order->quantity;
                $cost = $productService->getCogs($item->product, $item->unit);

                $this->inventory->consume($order->id, $item->product_id, $item->unit_id, $required, $cost);
            }

            $order->status = ManufacturingOrder::STATUS_IN_PROGRESS;
            $order->started_at = now();
            $order->save();
        });
    }

    public function completeOrder(ManufacturingOrder $order)
    {
        if ($order->status !== ManufacturingOrder::STATUS_IN_PROGRESS) {
             if ($order->status === ManufacturingOrder::STATUS_PLANNED || $order->status === ManufacturingOrder::STATUS_DRAFT) {
                 $this->startOrder($order);
                 $order->refresh();
             } else {
                 throw new Exception("Order is not in progress.");
             }
        }

        DB::transaction(function() use ($order) {
            $estimatedUnitCost = $this->bomService->calculateEstimatedCost($order->bom);

            $this->inventory->produce($order->id, $order->product_id, $order->unit_id, $order->quantity, $estimatedUnitCost);

            $order->status = ManufacturingOrder::STATUS_COMPLETED;
            $order->completed_at = now();
            $order->save();
        });
    }
}
