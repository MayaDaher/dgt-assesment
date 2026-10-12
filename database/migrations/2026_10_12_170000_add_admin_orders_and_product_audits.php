<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Admin inbox: filter by status (optionally with a created_at range), newest first.
            $table->index(['status', 'created_at'], 'orders_status_created_at_index');
        });

        Schema::create('product_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_sku', 64);
            $table->string('product_name');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field', 32);
            $table->string('old_value', 64);
            $table->string('new_value', 64);
            $table->string('source', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['field', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_audits');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_created_at_index');
        });
    }
};
