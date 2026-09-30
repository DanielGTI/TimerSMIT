<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Designação explícita: `approver_id` aprova as semanas de `member_id`
        // (em todos os projetos se project_id for nulo, ou só naquele projeto).
        // Sem nenhuma designação aplicável, os administradores decidem (CL-002).
        // A gestão destas linhas é da tela de configuração (US5).
        Schema::create('approver_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('members')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'member_id']);
            $table->index('approver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approver_assignments');
    }
};
