<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->uuid('call_id')->primary();
            $table->unsignedBigInteger('channel_id');
            $table->unsignedBigInteger('initiator_id');
            $table->string('status'); // ringing, active, ended
            $table->timestamps();

            $table->foreign('channel_id')->references('id')->on('channels');
            $table->foreign('initiator_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
