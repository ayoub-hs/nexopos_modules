<?php

namespace Modules\NsContainerManagement\Crud;

use App\Services\CrudService;
use Modules\NsContainerManagement\Models\ContainerInventory;
use App\Services\Helper;
use App\Services\CrudEntry;

class ContainerInventoryCrud extends CrudService
{
    const IDENTIFIER = 'ns.container-inventory';

    protected $table = 'ns_container_inventory';
    protected $model = ContainerInventory::class;
    protected $namespace = 'ns.container-inventory';

    protected $relations = [
        [ 'ns_container_types as type', 'ns_container_inventory.container_type_id', '=', 'type.id' ],
    ];

    public function allowedTo(string $permission): void
    {
        // Allow all
    }
    
    public function getPermission(?string $name): bool|string
    {
        return true;
    }

    public function getLinks(): array
    {
        return [
            'list' => ns()->route('ns.dashboard.container-inventory'),
            'edit' => ns()->route('ns.dashboard.container-inventory'), // Fallback
        ];
    }

    public function getLabels()
    {
        return [
            'list_title' => __('Container Inventory'),
            'list_description' => __('Current stock levels for all container types.'),
            'no_entry' => __('No inventory records found.'),
        ];
    }

    public function getColumns(): array
    {
        return [
            'type_name' => [
                'label' => __('Container Type'),
                '$sort' => true,
            ],
            'quantity_on_hand' => [
                'label' => __('On Hand'),
                '$sort' => true,
            ],
            'quantity_reserved' => [
                'label' => __('Reserved'),
                '$sort' => true,
            ],
            'updated_at' => [
                'label' => __('Last Updated'),
                '$sort' => true,
            ],
        ];
    }

    public function setActions(CrudEntry $entry): CrudEntry
    {
        return $entry;
    }
}
