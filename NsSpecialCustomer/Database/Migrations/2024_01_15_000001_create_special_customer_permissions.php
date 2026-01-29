<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Permission;
use App\Models\Role;

return new class extends Migration
{
    public function up()
    {
        $permissions = [
            [
                'namespace' => 'special.customer.manage',
                'name' => __('Manage Special Customers'),
                'description' => __('Full access to manage special customers, cashback, and settings.'),
            ],
            [
                'namespace' => 'special.customer.view',
                'name' => __('View Special Customers'),
                'description' => __('View special customer information and balances.'),
            ],
            [
                'namespace' => 'special.customer.cashback',
                'name' => __('Manage Cashback'),
                'description' => __('Process and manage cashback rewards.'),
            ],
            [
                'namespace' => 'special.customer.topup',
                'name' => __('Process Top-ups'),
                'description' => __('Add funds to special customer accounts.'),
            ],
            [
                'namespace' => 'special.customer.settings',
                'name' => __('Configure Settings'),
                'description' => __('Configure special customer module settings.'),
            ],
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['namespace' => $perm['namespace']], $perm);
        }

        // Assign to roles
        Role::namespace(Role::ADMIN)->addPermissions([
            'special.customer.manage',
            'special.customer.view',
            'special.customer.cashback',
            'special.customer.topup',
            'special.customer.settings',
        ]);

        Role::namespace(Role::STOREADMIN)->addPermissions([
            'special.customer.manage',
            'special.customer.view',
            'special.customer.cashback',
            'special.customer.topup',
        ]);
    }

    public function down()
    {
        $namespaces = [
            'special.customer.manage',
            'special.customer.view',
            'special.customer.cashback',
            'special.customer.topup',
            'special.customer.settings',
        ];

        Permission::whereIn('namespace', $namespaces)->delete();
    }
};

