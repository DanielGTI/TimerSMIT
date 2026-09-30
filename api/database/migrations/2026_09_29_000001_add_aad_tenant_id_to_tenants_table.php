<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // GUID do tenant do Azure AD/Entra, extraído do claim `tid` do
            // token da extensão (verificado por assinatura, nunca afirmado
            // pelo cliente). Âncora anti-spoofing: uma vez associada a um
            // tenant Entra, uma organização não pode trocar de dono
            // silenciosamente (ver IdentityProvisioningService).
            $table->string('aad_tenant_id')->after('devops_organization_id');
            $table->index('aad_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['aad_tenant_id']);
            $table->dropColumn('aad_tenant_id');
        });
    }
};
