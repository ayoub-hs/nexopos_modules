<?php

namespace Modules\NsManufacturing\Services;

use Modules\NsManufacturing\Models\ManufacturingBom;
use Modules\NsManufacturing\Models\ManufacturingBomItem;
use App\Models\Product; 
use Exception;

class BomService
{
    public function calculateEstimatedCost(ManufacturingBom $bom): float
    {
        $totalCost = 0;
        $productService = app()->make(\App\Services\ProductService::class);
        
        foreach ($bom->items as $item) {
             $cogs = $productService->getCogs($item->product, $item->unit);              
             $totalCost += $item->quantity * $cogs;
        }
        return $totalCost;
    }

    public function validateCircularDependency(int $bomId, int $componentProductId): bool
    {
        $targetBom = ManufacturingBom::find($bomId);
        if (!$targetBom) return true; 

        $outputProductId = $targetBom->product_id;

        if ($outputProductId == $componentProductId) {
             return false; 
        }

        return $this->checkDownstream($componentProductId, $outputProductId);
    }

    private function checkDownstream(int $currentProductId, int $originalOutputId, $visited = []): bool
    {
        if (in_array($currentProductId, $visited)) return true; 
        $visited[] = $currentProductId;

        $bomsProducingThis = ManufacturingBom::where('product_id', $currentProductId)->get();

        foreach ($bomsProducingThis as $bom) {
            foreach ($bom->items as $item) {
                if ($item->product_id == $originalOutputId) {
                    return false; 
                }
                
                if (!$this->checkDownstream($item->product_id, $originalOutputId, $visited)) {
                    return false;
                }
            }
        }
        return true;
    }
}
