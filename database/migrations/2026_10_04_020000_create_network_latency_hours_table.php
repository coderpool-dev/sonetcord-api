<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('network_latency_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('hour');
            $table->string('metric', 8);
            $table->char('city_key', 64);
            $table->string('city', 160);
            $table->string('country_code', 2)->nullable();
            $table->unsignedBigInteger('rtt_sum')->default(0);
            $table->unsignedInteger('successes')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedInteger('last_rtt')->nullable();
            $table->boolean('last_ok');
            $table->dateTime('last_at');
            $table->unique(['user_id', 'hour', 'metric', 'city_key'], 'latency_user_hour_city');
            $table->index(['hour', 'metric']);
            $table->index('last_at');
        });
    }

    public function down(): void { Schema::dropIfExists('network_latency_hours'); }
};
