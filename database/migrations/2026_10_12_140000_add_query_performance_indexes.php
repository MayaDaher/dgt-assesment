<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Default listing: WHERE status = active ORDER BY created_at DESC.
            $table->index(['status', 'created_at'], 'products_status_created_at_index');
            // Category browse plus price filter/sort among active rows.
            $table->index(['status', 'category_id', 'price'], 'products_status_category_price_index');
            // SoftDeletes adds `deleted_at IS NULL` to every catalog query.
            $table->index('deleted_at', 'products_deleted_at_index');
        });

        Schema::table('categories', function (Blueprint $table) {
            // Active tree: roots (`parent_id IS NULL`) and children of one parent.
            $table->index(['is_active', 'parent_id'], 'categories_active_parent_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            // A customer's order history, optionally filtered by status, newest first.
            $table->index(['user_id', 'status', 'created_at'], 'orders_user_status_created_at_index');
        });

        Schema::table('promotions', function (Blueprint $table) {
            // Soft-deleted coupons must stay out of code lookups.
            $table->index('deleted_at', 'promotions_deleted_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_status_created_at_index');
            $table->dropIndex('products_status_category_price_index');
            $table->dropIndex('products_deleted_at_index');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('categories_active_parent_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_user_status_created_at_index');
        });

        Schema::table('promotions', function (Blueprint $table) {
            $table->dropIndex('promotions_deleted_at_index');
        });
    }
};
