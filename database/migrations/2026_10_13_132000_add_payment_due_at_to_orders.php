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
            $table->timestamp('payment_due_at')->nullable()->after('delivered_at');
            $table->index(['status', 'payment_due_at']);
        });

        $minutes = max(1, (int) config('orders.unpaid_pending_minutes', 30));

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                "UPDATE orders SET payment_due_at = DATE_ADD(created_at, INTERVAL {$minutes} MINUTE) WHERE status = 'pending' AND payment_due_at IS NULL"
            );
        } else {
            DB::statement(
                "UPDATE orders SET payment_due_at = datetime(created_at, '+{$minutes} minutes') WHERE status = 'pending' AND payment_due_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'payment_due_at']);
            $table->dropColumn('payment_due_at');
        });
    }
};
