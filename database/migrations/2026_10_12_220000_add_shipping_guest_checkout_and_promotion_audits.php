<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'idempotency_key']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_scope', 80)->nullable()->after('idempotency_key');
            $table->string('shipping_name')->nullable();
            $table->string('shipping_phone', 30)->nullable();
            $table->string('shipping_address_line1')->nullable();
            $table->string('shipping_address_line2')->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->string('shipping_state', 100)->nullable();
            $table->string('shipping_postal_code', 20)->nullable();
            $table->string('shipping_country', 100)->nullable();

            $table->unique(['idempotency_scope', 'idempotency_key']);
        });

        Schema::create('promotion_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('promotion_code', 50);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field', 32);
            $table->string('old_value', 100);
            $table->string('new_value', 100);
            $table->string('source', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['promotion_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['field', 'created_at']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::statement("UPDATE orders SET idempotency_scope = CONCAT('u:', user_id) WHERE idempotency_key IS NOT NULL AND user_id IS NOT NULL");
        } else {
            DB::statement("UPDATE orders SET idempotency_scope = 'u:' || user_id WHERE idempotency_key IS NOT NULL AND user_id IS NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_audits');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['idempotency_scope', 'idempotency_key']);
            $table->dropColumn([
                'idempotency_scope',
                'shipping_name',
                'shipping_phone',
                'shipping_address_line1',
                'shipping_address_line2',
                'shipping_city',
                'shipping_state',
                'shipping_postal_code',
                'shipping_country',
            ]);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['user_id', 'idempotency_key']);
        });
    }
};
