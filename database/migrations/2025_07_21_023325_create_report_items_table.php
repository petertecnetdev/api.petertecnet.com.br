<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateReportItemsTable extends Migration
{
    public function up()
    {
        Schema::create('report_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')
                  ->constrained('reports')
                  ->onDelete('cascade');
            $table->foreignId('item_id')
                  ->constrained('items')
                  ->onDelete('restrict');
            $table->string('item_name');
            $table->integer('quantity');
            $table->decimal('revenue', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('report_items');
    }
}
