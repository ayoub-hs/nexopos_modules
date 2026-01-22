<?php

namespace Modules\NsManufacturing\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
    protected $table = 'ns_work_orders';
    protected $fillable = ['reference','bom_id','product_id','quantity','status','planned_at','started_at','completed_at','warehouse_id','created_by'];

    public function bom()
    {
        return $this->belongsTo(Bom::class, 'bom_id');
    }

    public function lines()
    {
        return $this->hasMany(WorkOrderLine::class, 'work_order_id');
    }

    public function product()
    {
        return $this->belongsTo(\App\Models\Product::class, 'product_id');
    }
}