<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            // Lançamento trazido de outra ferramenta (ex.: "7pace:<hash>:1"). Único por
            // organização: repetir a importação do mesmo arquivo não duplica nada.
            $table->string('import_ref', 100)->nullable();
            $table->unique(['tenant_id', 'import_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'import_ref']);
            $table->dropColumn('import_ref');
        });
    }
};
