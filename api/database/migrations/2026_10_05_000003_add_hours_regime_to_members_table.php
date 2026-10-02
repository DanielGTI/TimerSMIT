<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // clt: horas adicionais podem virar hora extra, banco de horas ou a pagar.
            // pj: só "a pagar" (não há banco de horas).
            // none: não controla jornada (ex.: cargo de confiança) — não gera hora adicional.
            $table->string('hours_regime', 10)->default('clt')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('hours_regime');
        });
    }
};
