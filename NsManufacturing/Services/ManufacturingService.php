<?php

namespace Modules\NsManufacturing\Services;

use Modules\NsManufacturing\Models\WorkOrder;
use Modules\NsManufacturing\Models\BomLine;
use Modules\NsManufacturing\Models\ProductionTransaction;
use Illuminate\Support\Facades\DB;

class ManufacturingService
{
    // Note: This service contains core business logic. It uses a conservative approach
    // and includes TODO markers where integration with NexoPOS inventory APIs are required.

    public function completeWorkOrder(WorkOrder $workOrder, $userId = null)
    {
        if ($workOrder->status === 'done') {
            throw new \Exception('Work order already completed');
        }

        DB::beginTransaction();
        try {
            // Determine bom lines
            $bom = $workOrder->bom;
            if (!$bom) {
                // If no BOM, assume produced product has no components
                // Still create a produce transaction
            }

            $warehouseId = $workOrder->warehouse_id;

            // Consume components
            if ($bom) {
                foreach ($bom->lines as $line) {
                    $required = bcmul((string)$line->quantity, (string)$workOrder->quantity, 4);

                    // TODO: Replace with NexoPOS stock adjustment service
                    $this->decreaseProductStock($line->component_product_id, $warehouseId, $required);

                    ProductionTransaction::create([
                        'work_order_id' => $workOrder->id,
                        'type' => 'consume',
                        'product_id' => $line->component_product_id,
                        'quantity' => $required,
                        'warehouse_id' => $warehouseId,
                        'created_by' => $userId,
                    ]);
                }
            }

            // Produce finished goods
            $producedQty = $workOrder->quantity;
            $this->increaseProductStock($workOrder->product_id, $warehouseId, $producedQty);

            ProductionTransaction::create([
                'work_order_id' => $workOrder->id,
                'type' => 'produce',
                'product_id' => $workOrder->product_id,
                'quantity' => $producedQty,
                'warehouse_id' => $warehouseId,
                'created_by' => $userId,
            ]);

            $workOrder->status = 'done';
            $workOrder->completed_at = now();
            $workOrder->save();

            DB::commit();

            return $workOrder;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function decreaseProductStock($productId, $warehouseId, $qty)
    {
        // Placeholder: adapt to NexoPOS stock adjustment implementation.
        // Many NexoPOS installs have tables/services for stock movements. Implement integration here.

        // Example conservative fallback: if products table has 'stock' column
        try {
            DB::table('products')->where('id', $productId)->decrement('stock', $qty);
        } catch (\Throwable $e) {
            // If operation fails because column/table differs, bubble up with informative message
            throw new \Exception('Stock decrease failed: adapt ManufacturingService to NexoPOS stock API. ' . $e->getMessage());
        }
    }

    protected function increaseProductStock($productId, $warehouseId, $qty)
    {
        try {
            DB::table('products')->where('id', $productId)->increment('stock', $qty);
        } catch (\Throwable $e) {
            throw new \Exception('Stock increase failed: adapt ManufacturingService to NexoPOS stock API. ' . $e->getMessage());
        }
    }
}