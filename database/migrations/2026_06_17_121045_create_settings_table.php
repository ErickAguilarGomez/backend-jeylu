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
        Schema::create('social_media_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->unique()->index();
            $table->string('url', 500)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('default_message')->nullable();
            $table->string('icon', 100)->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });

        // Insert initial seeds
        DB::table('social_media_settings')->insert([
            [
                'type' => 'whatsapp',
                'url' => null,
                'phone' => '51999999999',
                'default_message' => "¡Hola JEILU Store! Quiero comprar el siguiente producto:\n\n*Producto:* {product_name}\n*SKU:* {product_sku}\n*Precio:* S/ {product_price}\n\n¿Tienen disponibilidad para envío inmediato?",
                'icon' => 'whatsapp',
                'active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_media_settings');
    }
};
