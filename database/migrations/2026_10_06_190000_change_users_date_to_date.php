<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Дата рождения лежала в TIMESTAMP, а он в MySQL хранит только 1970–2038:
 * регистрация с годом рождения до 1970 падала с 500. Переводим колонку в DATE.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Полночь по местному поясу, сохранённая в UTC (например, 21:00 накануне для Москвы),
            // при обрезке до даты ушла бы на день назад — сдвигаем такие значения на следующие сутки.
            DB::statement('UPDATE users SET `date` = DATE(`date`) + INTERVAL 1 DAY
                WHERE MINUTE(`date`) = 0 AND SECOND(`date`) = 0 AND HOUR(`date`) >= 12');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->date('date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('date')->nullable()->change();
        });
    }
};
