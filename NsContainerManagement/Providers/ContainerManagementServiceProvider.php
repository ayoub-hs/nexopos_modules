<?php

namespace Modules\NsContainerManagement\Providers;

use Illuminate\Support\ServiceProvider;
use TorMorten\Eventy\Facades\Events as Hook;
use Modules\NsContainerManagement\Services\ContainerService;
use Modules\NsContainerManagement\Services\ContainerLedgerService;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnitQuantity;
use App\Events\OrderAfterCreatedEvent;
use Illuminate\Support\Facades\Event;
use App\Events\RenderFooterEvent;

class ContainerManagementServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(ContainerService::class, function ($app) {
            return new ContainerService();
        });

        $this->app->singleton(ContainerLedgerService::class, function ($app) {
            return new ContainerLedgerService($app->make(\App\Services\OrdersService::class));
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->loadViewsFrom(__DIR__ . '/../Resources/Views', 'nscontainermanagement');

        // Listen for order creation to record container movements
        Event::listen(OrderAfterCreatedEvent::class, \Modules\NsContainerManagement\Listeners\OrderAfterCreatedListener::class);

        /**
         * Handle Product Saving to Link Containers
         */
        Hook::addAction('ns-after-save-product', function($product, $request) {
            $containerService = app(ContainerService::class);
            
            if ($request->has('variations')) {
                foreach ($request->input('variations') as $variationData) {
                    if (isset($variationData['tabs']['units']['fields']['selling_group']['groups'])) {
                        foreach ($variationData['tabs']['units']['fields']['selling_group']['groups'] as $group) {
                            $unitId = null;
                            $typeId = null;
                            
                            foreach ($group['fields'] as $field) {
                                if ($field['name'] === 'unit_id') {
                                    $unitId = $field['value'];
                                }
                                if ($field['name'] === 'container_type_id') {
                                    $typeId = $field['value'];
                                }
                            }

                            if ($unitId) {
                                if (empty($typeId)) {
                                    $containerService->unlinkProductFromContainer($product->id, $unitId);
                                } else {
                                    $containerService->linkProductToContainer($product->id, (int) $typeId, $unitId);
                                }
                            }
                        }
                    }
                }
            } else {
                // Handle simple product
                $sellingGroup = $request->input('tabs.units.fields.selling_group.groups');
                if ($sellingGroup) {
                    foreach ($sellingGroup as $group) {
                        $unitId = null;
                        $typeId = null;
                        foreach ($group['fields'] as $field) {
                            if ($field['name'] === 'unit_id') {
                                $unitId = $field['value'];
                            }
                            if ($field['name'] === 'container_type_id') {
                                $typeId = $field['value'];
                            }
                        }
                        if ($unitId) {
                            if (empty($typeId)) {
                                $containerService->unlinkProductFromContainer($product->id, $unitId);
                            } else {
                                $containerService->linkProductToContainer($product->id, (int) $typeId, $unitId);
                            }
                        }
                    }
                }
            }
        });

        // Register CRUD resources
        Hook::addFilter('ns-crud-resource', function ($resource) {
            if ($resource === 'ns.container-types') {
                return \Modules\NsContainerManagement\Crud\ContainerTypeCrud::class;
            }
            if ($resource === 'ns.container-inventory') {
                return \Modules\NsContainerManagement\Crud\ContainerInventoryCrud::class;
            }
            if ($resource === 'ns.container-receive') {
                return \Modules\NsContainerManagement\Crud\ReceiveContainerCrud::class;
            }
            if ($resource === 'ns.container-customers') {
                return \Modules\NsContainerManagement\Crud\CustomerBalanceCrud::class;
            }
            if ($resource === 'ns.container-adjustment') {
                return \Modules\NsContainerManagement\Crud\ContainerAdjustmentCrud::class;
            }
            return $resource;
        });

        /**
         * Add Container field to each Unit in Product CRUD
         */
        Hook::addFilter('ns-products-units-quantities-fields', function($fields) {
            $containerService = app(ContainerService::class);
            $types = $containerService->getContainerTypesDropdown();

            $fields[] = [
                'type' => 'select',
                'name' => 'container_type_id',
                'label' => __('Container'),
                'options' => array_merge([['value' => '', 'label' => __('None')]], $types),
                'description' => __('Link a container to this specific unit.'),
                'validation' => '',
                'value' => '',
            ];

            return $fields;
        });

        /**
         * We need a way to populate the values for existing units.
         */
        Hook::addFilter('ns-products-crud-form', function($form, $entry) {
            if (!$entry || !$entry->id) {
                return $form;
            }

            $containerService = app(ContainerService::class);

            // Handle Simple Product units tab
            if (isset($form['tabs']['units']['fields'])) {
                foreach ($form['tabs']['units']['fields'] as &$field) {
                    if ($field['name'] === 'selling_group' && isset($field['groups'])) {
                        foreach ($field['groups'] as &$group) {
                            $unitId = null;
                            foreach ($group['fields'] as $f) {
                                if ($f['name'] === 'unit_id') {
                                    $unitId = $f['value'] ?? null;
                                    break;
                                }
                            }

                            if ($unitId) {
                                $link = $containerService->getProductContainer($entry->id, $unitId);
                                $typeId = $link ? $link->container_type_id : '';

                                foreach ($group['fields'] as &$f) {
                                    if ($f['name'] === 'container_type_id') {
                                        $f['value'] = $typeId;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Also check within variations for variable products
            if (isset($form['variations']) && is_array($form['variations'])) {
                foreach ($form['variations'] as &$variation) {
                    if (isset($variation['tabs']['units']['fields'])) {
                        foreach ($variation['tabs']['units']['fields'] as &$field) {
                            if ($field['name'] === 'selling_group' && isset($field['groups'])) {
                                foreach ($field['groups'] as &$group) {
                                    $unitId = null;
                                    foreach ($group['fields'] as $f) {
                                        if ($f['name'] === 'unit_id') {
                                            $unitId = $f['value'] ?? null;
                                            break;
                                        }
                                    }

                                    if ($unitId) {
                                        $link = $containerService->getProductContainer($entry->id, $unitId);
                                        $typeId = $link ? $link->container_type_id : '';

                                        foreach ($group['fields'] as &$f) {
                                            if ($f['name'] === 'container_type_id') {
                                                $f['value'] = $typeId;
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            return $form;
        }, 20, 2);

        /**
         * Sanitize product inputs to prevent SQL errors
         * for columns that don't exist in nexopos_products
         */
        Hook::addFilter('ns-update-products-inputs', function($inputs) {
            unset($inputs['container_type_id']);
            return $inputs;
        });

        // Register dashboard menu
        Hook::addFilter('ns-dashboard-menus', function ($menus) {
            $containerMenu = [
                'label' => __('Container Management'),
                'icon' => 'la-boxes',
                'childrens' => [
                    'container-types' => [
                        'label' => __('Container Types'),
                        'href' => ns()->route('ns.dashboard.container-types'),
                    ],
                    'container-inventory' => [
                        'label' => __('Inventory'),
                        'href' => ns()->route('ns.dashboard.container-inventory'),
                    ],
                    'adjust-stock' => [
                        'label' => __('Adjust Stock'),
                        'href' => ns()->route('ns.dashboard.container-adjust'),
                    ],
                    'receive-containers' => [
                        'label' => __('Receive Containers'),
                        'href' => ns()->route('ns.dashboard.container-receive'),
                    ],
                    'customer-balances' => [
                        'label' => __('Customer Balances'),
                        'href' => ns()->route('ns.dashboard.container-customers'),
                    ],
                    'container-reports' => [
                        'label' => __('Reports'),
                        'href' => ns()->route('ns.dashboard.container-reports'),
                    ],
                ],
            ];

            if (isset($menus['orders'])) {
                $newMenus = [];
                foreach ($menus as $key => $value) {
                    $newMenus[$key] = $value;
                    if ($key === 'orders') {
                        $newMenus['container-management'] = $containerMenu;
                    }
                }
                return $newMenus;
            }

            $menus['container-management'] = $containerMenu;
            return $menus;
        });

        // Inject POS options and footer
        Hook::addFilter('ns-pos-options', function($options) {
            $containerService = app(ContainerService::class);
            $options['container_management'] = [
                'enabled' => true,
                'types' => $containerService->getContainerTypesDropdown(),
                'links' => $containerService->getAllProductContainerLinks(),
            ];
            return $options;
        });

        // Use RenderFooterEvent to inject the pos-footer view
        Event::listen(RenderFooterEvent::class, function (RenderFooterEvent $event) {
            // Only inject on the POS route (ns.dashboard.pos)
            if ($event->routeName === 'ns.dashboard.pos') {
                $event->output->addView('nscontainermanagement::pos-footer');
            }
        });
    }
}
