<?php

namespace Modules\NsManufacturing\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use TorMorten\Eventy\Facades\Events as Hook;
use Modules\NsManufacturing\Crud\BomCrud;
use Modules\NsManufacturing\Crud\BomItemCrud;
use Modules\NsManufacturing\Crud\ProductionOrderCrud;

class NsManufacturingServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Singleton Services can be registered here if needed
    }

    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'ns-manufacturing');

        View::composer('ns-manufacturing::*', function ($view) {
            $view->with('manufacturingConfig', config('ns-manufacturing'));
        });

        // Register CRUDs
        Hook::addFilter('ns-crud-resource', function ($resource) {
            if ($resource === 'ns.manufacturing-boms') return BomCrud::class;
            if ($resource === 'ns.manufacturing-bom-items') return BomItemCrud::class;
            if ($resource === 'ns.manufacturing-orders') return ProductionOrderCrud::class;
            return $resource;
        });
        
        // Dashboard Menu
        Hook::addFilter('ns-dashboard-menus', function ($menus) {
            $menus[] = [
                'label' => __('Manufacturing'),
                'icon' => 'la-industry',
                'childrens' => [
                    ['label' => __('Bill of Materials'), 'href' => ns()->route('ns.dashboard.manufacturing-boms')],
                    ['label' => __('Production Orders'), 'href' => ns()->route('ns.dashboard.manufacturing-orders')],
                    ['label' => __('Reports'), 'href' => ns()->route('ns.dashboard.manufacturing-reports')],
                ]
            ];
            return $menus;
        });

        // Register Stock Hooks
        Hook::addFilter('ns-products-decrease-actions', function ($actions) {
            $actions[] = 'manufacturing_consume';
            return $actions;
        });

        Hook::addFilter('ns-products-increase-actions', function ($actions) {
            $actions[] = 'manufacturing_produce';
            return $actions;
        });

        Hook::addFilter('ns-products-history-label', function ($label, $action) {
            if ($action === 'manufacturing_consume') return __('Manufacturing Consumption');
            if ($action === 'manufacturing_produce') return __('Manufacturing Output');
            return $label;
        }, 10, 2);

        /**
         * For documented compatibility with ns-product-history-operation (singular)
         * and ns-products-history-operation (plural)
         */
        $historyOperationFilter = function ($label, $action) {
            if ($action === 'manufacturing_consume') return __('Manufacturing Consumption');
            if ($action === 'manufacturing_produce') return __('Manufacturing Output');
            return $label;
        };

        Hook::addFilter('ns-product-history-operation', $historyOperationFilter, 10, 2);
        Hook::addFilter('ns-products-history-operation', $historyOperationFilter, 10, 2);
    }
}
