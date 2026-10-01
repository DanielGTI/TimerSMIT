<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Situação no Azure DevOps na última sincronização da lista de
            // pessoas: nulo = nunca sincronizada; verdadeiro = com licença
            // ativa; falso = saiu da lista (sem licença/removida). Não
            // interfere em login nem em dados já lançados.
            $table->boolean('directory_active')->nullable()->after('email');
            $table->timestamp('directory_synced_at')->nullable()->after('directory_active');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['directory_active', 'directory_synced_at']);
        });
    }
};
