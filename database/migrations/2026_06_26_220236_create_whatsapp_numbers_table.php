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
        Schema::create('whatsapp_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('alias')->nullable();
            $table->string('phone', 50)->unique();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });

        // Migrar el WhatsApp existente en social_media_settings
        $oldWhatsapp = DB::table('social_media_settings')->where('type', 'whatsapp')->first();
        if ($oldWhatsapp) {
            DB::table('whatsapp_numbers')->insert([
                'alias' => 'Contacto Principal',
                'phone' => $oldWhatsapp->phone ?? '51999999999',
                'is_active' => (bool) ($oldWhatsapp->active ?? true),
                'display_order' => (int) ($oldWhatsapp->sort_order ?? 1),
                'created_by' => $oldWhatsapp->created_by ?? 1,
                'updated_by' => $oldWhatsapp->updated_by ?? 1,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Eliminar el registro antiguo para evitar duplicación
            DB::table('social_media_settings')->where('type', 'whatsapp')->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-crear el registro en social_media_settings si existiera algún whatsapp en whatsapp_numbers
        $firstNumber = DB::table('whatsapp_numbers')->orderBy('display_order', 'asc')->first();
        if ($firstNumber) {
            DB::table('social_media_settings')->insert([
                'type' => 'whatsapp',
                'phone' => $firstNumber->phone,
                'default_message' => "¡Hola JEILU Store! Quiero comprar el siguiente producto:\n\n*Producto:* {product_name}\n*SKU:* {product_sku}\n*Precio:* S/ {product_price}\n\n¿Tienen disponibilidad para envío inmediato?",
                'icon' => 'whatsapp',
                'active' => $firstNumber->is_active,
                'sort_order' => $firstNumber->display_order,
                'created_by' => $firstNumber->created_by,
                'updated_by' => $firstNumber->updated_by,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        Schema::dropIfExists('whatsapp_numbers');
    }
};
