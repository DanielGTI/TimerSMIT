<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only, como audit_events: cada captura gera uma linha nova,
        // nunca sobrescreve a anterior (data-model.md: "snapshot sem
        // reescrever histórico"). Título/tipo aqui são cosméticos, enviados
        // pela extensão a partir do work item form já aberto — não são prova
        // de autorização (isso é RoleAssignment/ProjectPolicy).
        Schema::create('work_item_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('devops_work_item_id');
            $table->string('title');
            $table->string('work_item_type');
            $table->timestamp('captured_at');

            $table->index(['tenant_id', 'project_id', 'devops_work_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_snapshots');
    }
};
