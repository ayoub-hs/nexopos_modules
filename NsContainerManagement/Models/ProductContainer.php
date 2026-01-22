<?php

namespace Modules\NsContainerManagement\Models;

use App\Models\NsModel;
use App\Models\Product;
use App\Models\Unit;

class ProductContainer extends NsModel
{
    protected $table = 'ns_product_containers';

    protected $fillable = [
        'product_id',
        'unit_id',
        'container_type_id',
        'is_enabled',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function containerType()
    {
        return $this->belongsTo(ContainerType::class, 'container_type_id');
    }
}
