<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBomLinesTable extends Migration
{
    public function up()
    {
        Schema::create('ns_bom_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bom_id')->index();
            $table->unsignedBigInteger('component_product_id')->index();
            $table->decimal('quantity', 16, 4)->default(0);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->timestamps();

            $table->foreign('bom_id')->references('id')->on('ns_boms')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ns_bom_lines');
    }
}