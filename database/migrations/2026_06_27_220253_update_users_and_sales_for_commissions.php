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
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('commission_percentage', 5, 2)->default(0.00)->after('password');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('commission_percentage', 5, 2)->default(0.00)->after('total');
            $table->decimal('commission_amount', 10, 2)->default(0.00)->after('commission_percentage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['commission_percentage', 'commission_amount']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('commission_percentage');
        });
    }
};
