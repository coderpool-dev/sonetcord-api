<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('call_participants');
    }

    public function down(): void
    {
        // Старая таблица участников звонка больше не используется: статус живёт в channels_members.
    }
};
