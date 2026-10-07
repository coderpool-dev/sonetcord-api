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
        Schema::create('keys', function (Blueprint $table) {
            $table->id();
            $table->string("key")->unique(); // Добавил unique для ключа
            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->integer("days");
            $table->dateTime('activated_at')->nullable(); // Дата активации (может быть null если ключ не активирован)
            $table->foreignId('user_id')
                ->nullable() // Может быть null если ключ ещё не куплен
                ->constrained()
                ->nullOnDelete(); // При удалении пользователя ключ остаётся, но user_id становится null
            $table->timestamps(); // Добавил стандартные created_at и updated_at
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('keys');
    }
};
