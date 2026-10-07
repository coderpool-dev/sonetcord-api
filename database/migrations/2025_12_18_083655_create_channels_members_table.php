<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels_members', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('users_id');
            $table->unsignedBigInteger('channels_id');
            $table->string('status');


            $table->foreign('users_id')->references('id')->on('users');
            $table->foreign('channels_id')->references('id')->on('channels');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels_members');
    }
};
