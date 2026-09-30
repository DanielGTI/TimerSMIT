<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma linha por pessoa/semana/tenant (a "submissão corrente"); o
        // histórico de cada envio fica em weekly_submission_revisions. A linha
        // só passa a existir no primeiro envio: semana sem linha = aberta.
        Schema::create('weekly_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            // Segunda-feira da semana, texto 'Y-m-d' (sem cast de data do Eloquent).
            $table->date('week_start_date');
            $table->string('status')->default('open'); // open | submitted | rejected | approved
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            // Preenchido pela designação de aprovadores (US3/US5).
            $table->foreignId('approver_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'member_id', 'week_start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_submissions');
    }
};
