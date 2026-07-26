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
        Schema::table('general_discounts', function (Blueprint $table) {
            if (!Schema::hasColumn('general_discounts', 'type')) {
                $table->string('type', 20)->default('increase')->after('id');
            }
            if (!Schema::hasColumn('general_discounts', 'value')) {
                $table->decimal('value', 5, 2)->default(0.00)->after('type');
            }
        });

        Schema::table('categories', function (Blueprint $table) {
            if (!Schema::hasColumn('categories', 'adjustment_type')) {
                $table->string('adjustment_type', 20)->nullable()->after('description');
            }
            if (!Schema::hasColumn('categories', 'adjustment_value')) {
                $table->decimal('adjustment_value', 5, 2)->nullable()->after('adjustment_type');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'adjustment_type')) {
                $table->string('adjustment_type', 20)->nullable()->after('purchase_price');
            }
            if (!Schema::hasColumn('products', 'adjustment_value')) {
                $table->decimal('adjustment_value', 5, 2)->nullable()->after('adjustment_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('general_discounts', function (Blueprint $table) {
            $table->dropColumn(['type', 'value']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['adjustment_type', 'adjustment_value']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['adjustment_type', 'adjustment_value']);
        });
    }
};
