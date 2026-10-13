<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'guest_token') || Schema::hasColumn('orders', 'guest_email')) {
            $guestOrderIds = DB::table('orders')->whereNull('user_id')->pluck('id');

            if ($guestOrderIds->isNotEmpty()) {
                DB::table('order_status_changes')->whereIn('order_id', $guestOrderIds)->delete();
                DB::table('promotion_usages')->whereIn('order_id', $guestOrderIds)->delete();
                DB::table('order_items')->whereIn('order_id', $guestOrderIds)->delete();
                DB::table('orders')->whereIn('id', $guestOrderIds)->delete();
            }

            Schema::table('orders', function (Blueprint $table) {
                $drop = array_values(array_filter([
                    Schema::hasColumn('orders', 'guest_email') ? 'guest_email' : null,
                    Schema::hasColumn('orders', 'guest_token') ? 'guest_token' : null,
                ]));

                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });

            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
            });

            Schema::table('orders', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }

        if (Schema::hasColumn('promotion_usages', 'email')) {
            DB::table('promotion_usages')->whereNull('user_id')->delete();

            Schema::table('promotion_usages', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropIndex(['promotion_id', 'email']);
                $table->dropColumn('email');
            });

            Schema::table('promotion_usages', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
            });

            Schema::table('promotion_usages', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'guest_email')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
            });

            Schema::table('orders', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
                $table->string('guest_email')->nullable()->after('user_id');
                $table->uuid('guest_token')->nullable()->after('guest_email');
                $table->index('guest_token');
            });
        }

        if (! Schema::hasColumn('promotion_usages', 'email')) {
            Schema::table('promotion_usages', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table('promotion_usages', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
                $table->string('email')->nullable()->after('user_id');
            });

            Schema::table('promotion_usages', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->index(['promotion_id', 'email']);
            });
        }
    }
};
