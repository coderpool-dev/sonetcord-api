<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Голосовые каналы серверов переиспользуют CallSession как есть (одна строка = одно
 * подключённое устройство, живо пока идёт heartbeat) - большая часть CallPresenceService
 * уже работает по call_id и channel_id используется только как денормализованный ключ
 * для нескольких точечных запросов. channel_id становится nullable: у сессии задан
 * ровно один из двух ключей.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('server_channel_id')->nullable()->after('channel_id');
            $table->foreign('server_channel_id')->references('id')->on('server_channels');
            $table->index('server_channel_id');
        });

        Schema::table('call_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('channel_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('call_sessions', function (Blueprint $table) {
            $table->dropForeign(['server_channel_id']);
            $table->dropIndex(['server_channel_id']);
            $table->dropColumn('server_channel_id');
        });

        Schema::table('call_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('channel_id')->nullable(false)->change();
        });
    }
};
