<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('ns_manufacturing_bom_items')) {
            Schema::table('ns_manufacturing_bom_items', function (Blueprint $table) {
                if (!Schema::hasColumn('ns_manufacturing_bom_items', 'author')) {
                    $table->unsignedBigInteger('author')->nullable()->after('cost_allocation');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('ns_manufacturing_bom_items')) {
            Schema::table('ns_manufacturing_bom_items', function (Blueprint $table) {
                if (Schema::hasColumn('ns_manufacturing_bom_items', 'author')) {
                    $table->dropColumn('author');
                }
            });
        }
    }
};
