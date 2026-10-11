<?php

use App\Enums\ProductStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('name');
            $table->string('sku', 64)->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->unsigned();
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->string('status', 20)->default(ProductStatus::Active->value);
            $table->timestamps();
            $table->softDeletes();

            // Listing filters: status is always applied, then price range / availability.
            $table->index(['status', 'price']);
            $table->index(['status', 'stock_quantity']);
            $table->index('name');
            $table->index('created_at');
        });

        // Last line of defence for inventory: the database itself rejects negative values,
        // even if application code has a bug. SQLite (used in tests) is skipped.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_quantity_non_negative CHECK (stock_quantity >= 0)');
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_non_negative CHECK (price >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
