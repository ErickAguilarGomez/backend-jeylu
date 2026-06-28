<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. En la tabla stores, agregar type varchar(20) default 'tienda'
        Schema::table('stores', function (Blueprint $table) {
            $table->string('type', 20)->default('tienda')->after('phone');
        });

        // 2. En la tabla products, agregar unique a la columna 'name'
        // Pero primero nos aseguramos de que no haya nombres duplicados
        $duplicates = DB::select("
            SELECT name, COUNT(*) as c 
            FROM products 
            GROUP BY name 
            HAVING c > 1
        ");
        
        foreach ($duplicates as $dup) {
            $productsWithSameName = DB::select("SELECT id FROM products WHERE name = ? ORDER BY id ASC", [$dup->name]);
            // El primero se queda igual, los siguientes se les añade un sufijo
            array_shift($productsWithSameName); // Dejar el primero
            foreach ($productsWithSameName as $index => $p) {
                $suffix = ' (Duplicado ' . ($index + 1) . ')';
                DB::update("UPDATE products SET name = CONCAT(name, ?) WHERE id = ?", [$suffix, $p->id]);
            }
        }

        Schema::table('products', function (Blueprint $table) {
            $table->unique('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
