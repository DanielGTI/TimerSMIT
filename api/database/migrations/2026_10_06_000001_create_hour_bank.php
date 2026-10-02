<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_rules', function (Blueprint $table) {
            // Prazo para compensar (CLT art. 59: 6 meses por acordo individual escrito,
            // 12 por acordo ou convenção coletiva). Vencido, o saldo vira hora extra a pagar.
            $table->unsignedTinyInteger('bank_validity_months')->default(6);
            // O banco recebe as horas ponderadas (com o fator do dia) ou as horas de relógio.
            $table->boolean('bank_weighted')->default(true);
        });

        Schema::table('additional_hour_reviews', function (Blueprint $table) {
            // Crédito no banco, congelado na classificação: quanto entrou e até quando vale.
            $table->unsignedInteger('bank_seconds')->nullable();
            $table->date('bank_expires_on')->nullable();
        });

        // Débitos (folga, pagamento em folha) e ajustes manuais, lançados pelo
        // administrador. Os créditos vêm das horas classificadas como banco.
        Schema::create('hour_bank_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);
            // Com sinal: negativo tira do saldo, positivo (só ajuste) acrescenta.
            $table->integer('seconds');
            $table->date('local_date');
            // Só para ajuste positivo: o crédito avulso vence como os demais.
            $table->date('expires_on')->nullable();
            $table->string('note', 500);
            $table->foreignId('created_by')->nullable()->constrained('members')->nullOnDelete();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'member_id']);
        });

        // Horas que já estavam classificadas como banco: entram com as horas
        // ponderadas e o prazo padrão de 6 meses a partir do dia trabalhado.
        $reviews = DB::table('additional_hour_reviews')
            ->join('time_entries', 'time_entries.id', '=', 'additional_hour_reviews.time_entry_id')
            ->where('additional_hour_reviews.classification', 'bank')
            ->select('additional_hour_reviews.id', 'additional_hour_reviews.weighted_seconds', 'time_entries.local_date')
            ->get();

        foreach ($reviews as $review) {
            DB::table('additional_hour_reviews')->where('id', $review->id)->update([
                'bank_seconds' => $review->weighted_seconds,
                'bank_expires_on' => Carbon::parse(substr((string) $review->local_date, 0, 10))->addMonthsNoOverflow(6)->toDateString(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hour_bank_movements');

        Schema::table('additional_hour_reviews', function (Blueprint $table) {
            $table->dropColumn(['bank_seconds', 'bank_expires_on']);
        });

        Schema::table('overtime_rules', function (Blueprint $table) {
            $table->dropColumn(['bank_validity_months', 'bank_weighted']);
        });
    }
};
