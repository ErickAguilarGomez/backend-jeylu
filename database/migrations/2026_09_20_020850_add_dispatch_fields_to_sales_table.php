<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'customer_phone')) {
                $table->string('customer_phone', 100)->nullable()->after('customer_name');
            }
            if (!Schema::hasColumn('sales', 'customer_email')) {
                $table->string('customer_email', 191)->nullable()->after('customer_phone');
            }
            if (!Schema::hasColumn('sales', 'delivery_type')) {
                $table->string('delivery_type', 50)->default('pickup')->after('customer_email');
            }
            if (!Schema::hasColumn('sales', 'delivery_address')) {
                $table->text('delivery_address')->nullable()->after('delivery_type');
            }
            if (!Schema::hasColumn('sales', 'order_notes')) {
                $table->text('order_notes')->nullable()->after('delivery_address');
            }
            if (!Schema::hasColumn('sales', 'dispatch_status')) {
                $table->string('dispatch_status', 50)->default('PENDING')->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn([
                'customer_phone',
                'customer_email',
                'delivery_type',
                'delivery_address',
                'order_notes',
                'dispatch_status'
            ]);
        });
    }
};
