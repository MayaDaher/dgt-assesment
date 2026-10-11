<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('description')->nullable();
            $table->string('type', 20);
            $table->decimal('value', 12, 2)->unsigned();
            $table->decimal('min_cart_amount', 12, 2)->unsigned()->nullable();
            $table->decimal('max_discount_amount', 12, 2)->unsigned()->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_value_valid CHECK (value > 0 AND (type <> 'percentage' OR value <= 100))");
            DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_used_count_within_limit CHECK (usage_limit IS NULL OR used_count <= usage_limit)');
            DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_dates_ordered CHECK (starts_at IS NULL OR ends_at IS NULL OR starts_at < ends_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
