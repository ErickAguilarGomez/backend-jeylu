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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });

        // Seed Categorías iniciales para JEILU
        $categories = [
            ['id' => 1, 'name' => 'Calzado Urbano', 'desc' => 'Zapatillas de calle y uso diario con estilo brutalista'],
            ['id' => 2, 'name' => 'Deportivo / Training', 'desc' => 'Calzado y ropa de alto rendimiento deportivo'],
            ['id' => 3, 'name' => 'Accesorios', 'desc' => 'Gorras, mochilas y complementos streetwear'],
        ];

        foreach ($categories as $cat) {
            $exists = DB::select("SELECT id FROM categories WHERE id = ?", [$cat['id']]);
            if (empty($exists)) {
                DB::insert("INSERT INTO categories (id, name, description, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)", [
                    $cat['id'], $cat['name'], $cat['desc'], 1, 1, now(), now()
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
