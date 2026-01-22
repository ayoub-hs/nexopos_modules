<?php

namespace Modules\NsManufacturing\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrderLine extends Model
{
    protected $table = 'ns_work_order_lines';
    protected $fillable = ['work_order_id','product_id','quantity','status'];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function product()
    {
        return $this->belongsTo(\App\Models\Product::class, 'product_id');
    }
}