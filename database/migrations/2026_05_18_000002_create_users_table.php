<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->default(3)->constrained('roles')->onDelete('restrict');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // Insertar el Admin y Vendedor iniciales en la migración
        $existsAdmin = DB::select("SELECT id FROM users WHERE email = ?", ['admin@jeilu.com']);
        if (empty($existsAdmin)) {
            $hashedPassword = Hash::make('Jeilu2026!');
            DB::insert("INSERT INTO users (id, role_id, name, email, password, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())", [
                1, 1, 'Administrador JEILU', 'admin@jeilu.com', $hashedPassword, 1, 1
            ]);
        }

        $existsSeller = DB::select("SELECT id FROM users WHERE email = ?", ['vendedor@jeilu.com']);
        if (empty($existsSeller)) {
            $hashedPassword = Hash::make('Vendedor2026!');
            DB::insert("INSERT INTO users (id, role_id, name, email, password, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())", [
                2, 2, 'Vendedor POS JEILU', 'vendedor@jeilu.com', $hashedPassword, 1, 1
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
