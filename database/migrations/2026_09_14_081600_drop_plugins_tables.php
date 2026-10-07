<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('plugin_likes');
        Schema::dropIfExists('plugins');
    }

    public function down(): void
    {
        Schema::create('plugins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description');
            $table->string('version', 20)->default('1.0.0');
            $table->json('tags')->nullable();
            $table->longText('code');
            $table->string('preview_url')->nullable();
            $table->unsignedInteger('downloads')->default(0);
            $table->boolean('is_approved')->default(true);
            $table->timestamps();
        });

        Schema::create('plugin_likes', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plugin_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'plugin_id']);
        });
    }
};
