<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 32)->unique();
            // Orders are financial records: a user with orders cannot be hard-deleted.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('promotion_code', 50)->nullable();
            $table->string('status', 20)->default(OrderStatus::Pending->value);
            $table->decimal('subtotal', 12, 2)->unsigned();
            $table->decimal('discount_amount', 12, 2)->unsigned()->default(0);
            $table->decimal('total', 12, 2)->unsigned();
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_totals_consistent CHECK (discount_amount <= subtotal AND total = subtotal - discount_amount)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
