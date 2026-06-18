<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cloudinary_configs', function (Blueprint $table) {
            $table->id();
            $table->string('cloud_name');
            $table->string('api_key');
            $table->string('api_secret');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });

        // Insert default Cloudinary credentials from user request
        DB::insert("INSERT INTO cloudinary_configs (cloud_name, api_key, api_secret, is_active, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", [
            'dwbw7bwlk',
            '315239792191814',
            'tC6bULA_RgeoI9xE2c6pF1hXaN8',
            1,
            1,
            1,
            now(),
            now()
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cloudinary_configs');
    }
};
