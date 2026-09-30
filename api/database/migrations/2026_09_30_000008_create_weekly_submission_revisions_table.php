<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: cada envio (inclusive reenvios após rejeição) gera uma
        // revisão com a "versão dos lançamentos" no instante do envio.
        Schema::create('weekly_submission_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_submission_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('idempotency_key');
            $table->timestamp('submitted_at');
            $table->unsignedInteger('total_seconds');
            $table->unsignedInteger('entry_count');
            // [{id, revision, localDate, durationSeconds, workItemId}]
            $table->json('entries_snapshot');
            $table->timestamps();

            $table->unique(['weekly_submission_id', 'revision']);
            // Repetir o mesmo envio (mesma Idempotency-Key) devolve o resultado, não erro.
            $table->unique(['weekly_submission_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_submission_revisions');
    }
};
