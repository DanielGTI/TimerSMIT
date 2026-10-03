<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4: hora extra informada e perfil de hora extra por pessoa.
 *
 *  - members.overtime_profile: pré-aprovada, padrão ou restrita (só CLT).
 *  - overtime_requests: (1) "Informar hora extra": a pessoa avisa antes (ou
 *    depois) e o aprovador decide; (2) "hora extra a confirmar": o trecho fora
 *    do expediente de quem tem perfil restrito, que só vira lançamento se o
 *    aprovador confirmar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('overtime_profile', 20)->default('standard')->after('hours_regime');
        });

        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            // request = hora extra informada; confirmation = hora extra a confirmar (perfil restrito).
            $table->string('kind', 20);
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedInteger('seconds_per_day');
            // Horário: previsto (informada, opcional) ou o trecho real (a confirmar).
            $table->string('start_time', 8)->nullable();
            $table->string('end_time', 8)->nullable();
            $table->text('reason');
            $table->string('suggested_destination', 20)->nullable();
            $table->boolean('after_the_fact')->default(false);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('approved_seconds_per_day')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            // Só na hora a confirmar: o lançamento que ela vira se for confirmada.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('devops_work_item_id')->nullable();
            $table->foreignId('activity_type_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->boolean('billable')->default(false);
            $table->string('timezone', 64)->nullable();
            $table->string('source', 20)->nullable();
            $table->foreignId('time_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'member_id', 'status']);
            $table->index(['tenant_id', 'date_from', 'date_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('overtime_profile');
        });
    }
};
