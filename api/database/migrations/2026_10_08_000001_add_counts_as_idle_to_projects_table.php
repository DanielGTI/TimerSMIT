<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // No relatório mensal de horas por projeto, as horas deste projeto (ex.:
            // estudo interno) não contam como produtivas: entram em "Horas Ociosas".
            $table->boolean('counts_as_idle')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('counts_as_idle');
        });
    }
};
