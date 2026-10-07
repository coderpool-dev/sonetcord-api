<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * История звонков для админки: когда звонок закончился и кто в нём был.
 * Сессии call_sessions удаляются при выходе, поэтому участников запоминаем отдельно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->timestamp('ended_at')->nullable()->after('understaffed_at');
            $table->index('created_at');
        });

        // До этой миграции конец звонка совпадал с последним обновлением строки — так его считала и админка.
        DB::table('calls')->where('status', 'ended')->update(['ended_at' => DB::raw('updated_at')]);

        Schema::create('call_attendances', function (Blueprint $table) {
            $table->id();
            $table->string('call_id', 64);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->unique(['call_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_attendances');

        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropColumn('ended_at');
        });
    }
};
