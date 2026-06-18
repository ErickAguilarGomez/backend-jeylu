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
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('address');
            $table->string('phone')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });

        // Tabla Pivote para asignar empleados (usuarios) a tiendas
        Schema::create('store_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->boolean('is_primary')->default(true);
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamps();

            $table->foreign('assigned_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['store_id', 'user_id']);
        });

        // Insertar la primera Tienda Principal JEILU
        $exists = DB::select("SELECT id FROM stores WHERE id = ?", [1]);
        if (empty($exists)) {
            DB::insert("INSERT INTO stores (id, name, address, phone, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
                1, 'Tienda Central JEILU (Central)', 'Av. Conquistadores 1240, San Isidro', '+51 1 4219999', 1, 1, now(), now()
            ]);

            // Asignar al Admin y al Vendedor a la tienda principal
            DB::insert("INSERT INTO store_user (store_id, user_id, is_primary, assigned_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)", [
                1, 1, 1, 1, now(), now()
            ]);
            DB::insert("INSERT INTO store_user (store_id, user_id, is_primary, assigned_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)", [
                1, 2, 1, 1, now(), now()
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_user');
        Schema::dropIfExists('stores');
    }
};
