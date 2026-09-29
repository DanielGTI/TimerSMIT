<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            // ID estável da organização do Azure DevOps (não o nome, que pode mudar).
            $table->string('devops_organization_id')->unique();
            $table->string('devops_organization_name');
            $table->string('default_timezone')->default('UTC');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
