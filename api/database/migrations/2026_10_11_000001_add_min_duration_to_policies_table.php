<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Duração mínima de um lançamento manual, definida pelo administrador nas
 * regras de lançamento (1 minuto = sem mínimo, como era).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_duration_minutes')->default(1)->after('duration_increment_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->dropColumn('min_duration_minutes');
        });
    }
};
