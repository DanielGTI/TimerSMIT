<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Nulo = política padrão do tenant; versionada para não reclassificar histórico (FR-010/US5).
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedSmallInteger('duration_increment_minutes')->default(1);
            $table->unsignedSmallInteger('daily_limit_hours')->default(24);
            $table->unsignedSmallInteger('retroactive_window_days')->default(30);
            $table->boolean('comment_required')->default(false);
            $table->timestamp('effective_from');
            $table->timestamps();

            $table->unique(['tenant_id', 'project_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
