<?php

namespace Modules\NsManufacturing\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionTransaction extends Model
{
    protected $table = 'ns_production_transactions';
    protected $fillable = ['work_order_id','type','product_id','quantity','warehouse_id','created_by'];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function product()
    {
        return $this->belongsTo(\App\Models\Product::class, 'product_id');
    }
}