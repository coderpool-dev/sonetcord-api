<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Первая версия таблицы (2025_12_18_083655) была без call_status — пересоздаём с полной схемой.
        Schema::dropIfExists('channels_members');

        Schema::create('channels_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('channels_id');
            $table->tinyInteger('status')->default(1)->comment('0=вышел, 1=участник, 2=админ');
            $table->tinyInteger('call_status')->default(0)->comment('0=нет, 1=ringing, 2=joined, 3=declined');
            $table->timestamp('last_call_seen')->nullable();
            $table->timestamps();

            $table->index(['channels_id', 'users_id']);
            $table->index(['channels_id', 'call_status']);
            $table->index('status');
            $table->unique(['channels_id', 'users_id']);
            $table->foreign('users_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('channels_id')->references('id')->on('channels')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels_members');
    }
};
