<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductionTransactionsTable extends Migration
{
    public function up()
    {
        Schema::create('ns_production_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id')->nullable()->index();
            $table->enum('type', ['consume','produce']);
            $table->unsignedBigInteger('product_id')->index();
            $table->decimal('quantity', 16, 4);
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ns_production_transactions');
    }
}