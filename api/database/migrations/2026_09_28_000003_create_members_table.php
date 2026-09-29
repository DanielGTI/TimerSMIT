<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Descriptor de identidade estável do Azure DevOps (subject), não o e-mail.
            $table->string('devops_identity_id');
            $table->string('display_name');
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'devops_identity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
