<?php

namespace Modules\NsManufacturing\Models;

use Illuminate\Database\Eloquent\Model;

class Bom extends Model
{
    protected $table = 'ns_boms';
    protected $fillable = ['product_id','name','notes'];

    public function lines()
    {
        return $this->hasMany(BomLine::class, 'bom_id');
    }

    public function product()
    {
        return $this->belongsTo(\App\Models\Product::class, 'product_id');
    }
}