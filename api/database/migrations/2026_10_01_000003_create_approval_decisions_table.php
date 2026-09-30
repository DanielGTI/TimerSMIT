<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: aprovação, rejeição e reabertura ficam todas aqui, e a
        // decisão anterior permanece no histórico quando a semana é reenviada.
        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('weekly_submission_id')->constrained()->cascadeOnDelete();
            // Revisão (envio) sobre a qual a decisão foi tomada.
            $table->unsignedInteger('revision');
            $table->foreignId('approver_id')->constrained('members')->cascadeOnDelete();
            $table->string('decision'); // approved | rejected | reopened
            $table->text('reason')->nullable();
            // Administrador decidindo a própria semana — permitido, mas marcado.
            $table->boolean('self_decision')->default(false);
            $table->string('idempotency_key');
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->unique(['weekly_submission_id', 'idempotency_key']);
            $table->index(['tenant_id', 'approver_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_decisions');
    }
};
