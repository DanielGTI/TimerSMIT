<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timer_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('devops_work_item_id');
            $table->foreignId('activity_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active'); // active | stopped
            $table->timestamp('started_at_utc');
            $table->timestamp('ended_at_utc')->nullable();
            $table->string('idempotency_key');
            $table->string('note', 2000)->nullable();
            $table->boolean('billable')->nullable();
            $table->timestamps();

            // Retentativa da mesma chamada de start (mesma Idempotency-Key)
            // deve devolver o mesmo timer, nunca criar outro (contracts/openapi.yaml).
            $table->unique(['tenant_id', 'member_id', 'idempotency_key']);
        });

        // Um timer ativo por membro por tenant (FR central de US1): índice
        // parcial, sintaxe idêntica em SQLite (testes) e PostgreSQL (produção).
        DB::statement(
            'CREATE UNIQUE INDEX timer_sessions_one_active_per_member '.
            "ON timer_sessions (tenant_id, member_id) WHERE status = 'active'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('timer_sessions');
    }
};
