<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            // Nulo = papel vale para todos os projetos do tenant (ex.: admin).
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role'); // member | approver | manager | admin
            $table->timestamps();

            $table->unique(['tenant_id', 'member_id', 'project_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
    }
};
