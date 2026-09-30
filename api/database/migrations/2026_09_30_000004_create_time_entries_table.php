<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('devops_work_item_id');
            // Nulo para lançamento manual; presente quando originado de stopTimer.
            $table->foreignId('timer_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('activity_type_id')->nullable()->constrained()->nullOnDelete();
            // Data e fuso local congelados no momento do lançamento
            // (data-model.md: "Temporal Semantics") — nunca recalculados
            // depois, mesmo que o membro mude de fuso.
            $table->date('local_date');
            $table->string('timezone');
            // Duração > 0 é reforçada em TimeSplitService/TimeEntryService
            // (validação de serviço), não em CHECK de banco — SQLite (testes)
            // não permite adicionar CHECK depois da criação da tabela, e o
            // restante do schema deste projeto também não usa CHECK.
            $table->unsignedInteger('duration_seconds');
            $table->string('source'); // timer | manual
            $table->boolean('billable')->default(true);
            $table->string('note', 2000)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->softDeletes(); // remoção lógica (data-model.md)

            $table->index(['tenant_id', 'member_id', 'local_date']);
            $table->index(['tenant_id', 'project_id', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
