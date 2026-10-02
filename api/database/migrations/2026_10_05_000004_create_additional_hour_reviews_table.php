<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('additional_hour_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            // Uma linha por lançamento: guarda só as decisões humanas. A hora adicional
            // em si é calculada a partir do lançamento e da regra vigente.
            $table->foreignId('time_entry_id')->constrained()->cascadeOnDelete();

            // Aprovador: "não autorizada" (a semana aprovada continua valendo).
            $table->string('denial_reason', 500)->nullable();
            $table->foreignId('denied_by')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('denied_at')->nullable();

            // Administrador: destino das horas. Os valores calculados ficam congelados
            // na classificação, para mudanças posteriores (feriado, regra) não os alterarem.
            $table->string('classification', 10)->nullable();
            $table->unsignedInteger('additional_seconds')->nullable();
            $table->unsignedInteger('weighted_seconds')->nullable();
            $table->foreignId('overtime_rule_id')->nullable()->constrained('overtime_rules')->nullOnDelete();
            $table->foreignId('classified_by')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('classified_at')->nullable();
            $table->string('classification_note', 500)->nullable();
            $table->timestamps();

            $table->unique('time_entry_id');
            $table->index(['tenant_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_hour_reviews');
    }
};
