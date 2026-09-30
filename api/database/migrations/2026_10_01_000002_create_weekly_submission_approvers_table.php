<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aprovadores designados resolvidos no momento de cada envio ("resolvido
        // no envio", CL-002). Mudar a designação depois não altera semanas já
        // enviadas; administradores sempre podem decidir.
        Schema::create('weekly_submission_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_submission_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->foreignId('approver_id')->constrained('members')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['weekly_submission_id', 'revision', 'approver_id'], 'submission_approvers_unique');
            $table->index('approver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_submission_approvers');
    }
};
