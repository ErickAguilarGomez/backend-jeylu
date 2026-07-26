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
            if (!Schema::hasColumn('sales', 'payment_method_id')) {
                $table->unsignedBigInteger('payment_method_id')->nullable()->after('customer_name');
                $table->foreign('payment_method_id')->references('id')->on('payment_methods')->onDelete('set null');
            }
            if (!Schema::hasColumn('sales', 'payment_method_name')) {
                $table->string('payment_method_name', 255)->nullable()->after('payment_method_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
            $table->dropColumn(['payment_method_id', 'payment_method_name']);
        });
    }
};
