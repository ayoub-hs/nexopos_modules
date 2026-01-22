<?php

namespace Modules\NsManufacturing\Providers;

use Illuminate\Support\ServiceProvider;

class ManufacturingServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'nsmanufacturing');

        // Load translations
        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'nsmanufacturing');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        // TODO: Register permissions and menu items following NexoPOS patterns
    }

    public function register()
    {
        // Register any bindings or singletons needed
    }
}