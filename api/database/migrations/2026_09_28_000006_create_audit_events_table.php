<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: nenhuma rota/serviço deve fazer UPDATE ou DELETE aqui (FR-011, SC-002).
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('actor_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('action'); // ex.: time_entry.created, week.approved, report.exported
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
