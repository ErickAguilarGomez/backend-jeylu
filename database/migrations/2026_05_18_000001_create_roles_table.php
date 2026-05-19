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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Insertar roles base: Admin, Vendedor, Usuario
        $roles = [
            ['id' => 1, 'name' => 'Admin', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Vendedor', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Usuario', 'created_at' => now(), 'updated_at' => now()],
        ];

        foreach ($roles as $role) {
            $exists = DB::select("SELECT id FROM roles WHERE id = ?", [$role['id']]);
            if (empty($exists)) {
                DB::insert("INSERT INTO roles (id, name, created_at, updated_at) VALUES (?, ?, ?, ?)", [
                    $role['id'], $role['name'], $role['created_at'], $role['updated_at']
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
