<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Snapshot fields stay even if the catalog product is later deleted.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('product_sku', 64);
            $table->decimal('unit_price', 12, 2)->unsigned();
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 12, 2)->unsigned();
            $table->timestamps();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_line_total_matches CHECK (line_total = unit_price * quantity)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
