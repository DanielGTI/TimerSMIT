<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Versionada como `policies`: cada alteração vale para os lançamentos
            // feitos daqui para a frente e nunca reclassifica o histórico.
            $table->unsignedInteger('version');
            $table->boolean('enabled')->default(false);
            // Expediente (hora local HH:MM); o que cai fora dele, em dia útil, é hora adicional.
            $table->string('workday_start', 5)->default('09:00');
            $table->string('workday_end', 5)->default('18:00');
            // Fatores multiplicadores por tipo de dia (1,50 = hora e meia por hora trabalhada).
            $table->decimal('factor_weekday', 4, 2)->default(1.50);
            $table->decimal('factor_saturday', 4, 2)->default(1.50);
            $table->decimal('factor_sunday', 4, 2)->default(2.00);
            $table->decimal('factor_holiday', 4, 2)->default(2.00);
            // Adicional noturno (CLT art. 73): 22h–5h, +20%, hora reduzida de 52min30s.
            $table->string('night_start', 5)->default('22:00');
            $table->string('night_end', 5)->default('05:00');
            $table->unsignedSmallInteger('night_percent')->default(20);
            $table->boolean('night_reduced_hour')->default(true);
            // Exige De/Até nos lançamentos manuais (sem horário não dá para saber se foi fora do expediente).
            $table->boolean('require_time_of_day')->default(true);
            $table->timestamp('effective_from');
            $table->timestamps();

            $table->unique(['tenant_id', 'version']);
        });

        // Organizações que já usam a ferramenta passam a controlar horas
        // adicionais a partir de agora (expediente 09–18, fatores 1,5/1,5/2/2).
        // Lançamentos anteriores não são reclassificados.
        $now = now();
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::table('overtime_rules')->insert([
                'tenant_id' => $tenantId,
                'version' => 1,
                'enabled' => true,
                'effective_from' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_rules');
    }
};
