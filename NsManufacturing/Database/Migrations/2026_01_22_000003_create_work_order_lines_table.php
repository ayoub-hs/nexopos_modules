<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWorkOrderLinesTable extends Migration
{
    public function up()
    {
        Schema::create('ns_work_order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->decimal('quantity', 16, 4);
            $table->enum('status', ['pending','consumed','returned'])->default('pending');
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('ns_work_orders')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ns_work_order_lines');
    }
}