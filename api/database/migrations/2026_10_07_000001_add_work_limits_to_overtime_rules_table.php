<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_rules', function (Blueprint $table) {
            // Limites que geram AVISO (nunca bloqueiam o lançamento), em minutos; 0 desliga.
            // CLT art. 59: até 2h extras por dia. CF art. 7º, XIII: 44h semanais.
            // CLT art. 66: 11h de descanso entre duas jornadas.
            $table->unsignedSmallInteger('alert_daily_extra_minutes')->default(120);
            $table->unsignedSmallInteger('alert_weekly_minutes')->default(2640);
            $table->unsignedSmallInteger('alert_rest_minutes')->default(660);
        });
    }

    public function down(): void
    {
        Schema::table('overtime_rules', function (Blueprint $table) {
            $table->dropColumn(['alert_daily_extra_minutes', 'alert_weekly_minutes', 'alert_rest_minutes']);
        });
    }
};
