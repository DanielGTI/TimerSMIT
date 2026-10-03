<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Marcação "faturável" (horas cobradas do cliente) só nos projetos que cobram
            // por hora. Desligado: o campo some do lançamento e dos relatórios, e as
            // horas do projeto nunca contam como faturáveis.
            $table->boolean('uses_billable')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('uses_billable');
        });
    }
};
