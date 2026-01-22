<?php

namespace Modules\NsManufacturing\Models;

use Illuminate\Database\Eloquent\Model;

class BomLine extends Model
{
    protected $table = 'ns_bom_lines';
    protected $fillable = ['bom_id','component_product_id','quantity','unit_id'];

    public function bom()
    {
        return $this->belongsTo(Bom::class, 'bom_id');
    }

    public function product()
    {
        return $this->belongsTo(\App\Models\Product::class, 'component_product_id');
    }
}