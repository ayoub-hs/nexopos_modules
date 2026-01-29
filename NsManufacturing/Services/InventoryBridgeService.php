<?php

namespace Modules\NsManufacturing\Services;

use App\Services\ProductService;
use App\Models\ProductUnitQuantity;
use App\Models\ProductHistory;
use Illuminate\Support\Facades\DB;
use Modules\NsManufacturing\Models\ManufacturingStockMovement;
use Exception;

class InventoryBridgeService
{
    public function __construct(
        protected ProductService $productService
    ) {}

    public function isAvailable(int $productId, int $unitId, float $quantity): bool
    {
        $currentStock = $this->productService->getQuantity($productId, $unitId);
        return $currentStock >= $quantity;
    }

    public function consume(int $orderId, int $productId, int $unitId, float $quantity, float $costAtTime = 0)
    {
        DB::transaction(function() use ($orderId, $productId, $unitId, $quantity, $costAtTime) {
            $this->productService->stockAdjustment('manufacturing_consume', [
                'product_id' => $productId,
                'unit_id' => $unitId,
                'quantity' => $quantity,
                'unit_price' => $costAtTime,
                'author' => auth()->id() ?? 99,
                'description' => "Manufacturing Consumption (Order #$orderId)",
                'order_id' => $orderId,
            ]);

            ManufacturingStockMovement::create([
                'order_id' => $orderId,
                'product_id' => $productId,
                'unit_id' => $unitId,
                'quantity' => -$quantity,
                'type' => ManufacturingStockMovement::TYPE_CONSUMPTION,
                'cost_at_time' => $costAtTime
            ]);
        });
    }

    public function produce(int $orderId, int $productId, int $unitId, float $quantity, float $costAtTime = 0)
    {
        DB::transaction(function() use ($orderId, $productId, $unitId, $quantity, $costAtTime) {
            $this->productService->stockAdjustment('manufacturing_produce', [
                'product_id' => $productId,
                'unit_id' => $unitId,
                'quantity' => $quantity,
                'unit_price' => $costAtTime, 
                'author' => auth()->id() ?? 99,
                'description' => "Manufacturing Output (Order #$orderId)",
                'order_id' => $orderId,
            ]);

            ManufacturingStockMovement::create([
                'order_id' => $orderId,
                'product_id' => $productId,
                'unit_id' => $unitId,
                'quantity' => $quantity,
                'type' => ManufacturingStockMovement::TYPE_PRODUCTION,
                'cost_at_time' => $costAtTime
            ]);
        });
    }
}
